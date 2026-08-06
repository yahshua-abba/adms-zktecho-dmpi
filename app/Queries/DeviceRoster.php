<?php

namespace App\Queries;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Models\EmployeeMap;
use App\Models\PinCollision;
use App\Support\PerPage;
use App\Sync\RfidConverter;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Who is on each time clock — the counts for the Devices list and the
 * per-person breakdown behind it.
 *
 * Two different lists answer "who is on this clock", and the whole value of
 * this screen is showing them side by side rather than picking one:
 *
 *  - `device_assignments` is what DMPI says *should* be on the clock (resolved
 *    through the device's linked payroll device code).
 *  - `device_enrollment` is what this server has actually *sent* to the clock
 *    (EnrollmentReconciler writes it at the moment it queues the command).
 *
 * Neither is "the truth on the machine": a clock that has been offline for a
 * week still has its changes sitting in `device_commands`. So a person is
 * reported as sent/not-sent/queued, never as confirmed-present, and the
 * queued flag is read from the command queue itself rather than implied.
 *
 * A payroll employee assigned to a clock who has no `employee_map` row cannot
 * be enrolled at all — either they never came down in the roster, or their PIN
 * is contested and deliberately left out of the map (see RosterSync). Those
 * are the rows worth acting on, so they sort first and carry their reason.
 */
class DeviceRoster
{
    /** Sent to the clock (or queued for it) and still assigned. */
    public const ON_CLOCK = 'on_clock';

    /** Assigned in payroll, not sent to the clock yet. */
    public const ADDING = 'adding';

    /** Sent to the clock before, no longer assigned — due to be removed. */
    public const REMOVING = 'removing';

    /** Assigned in payroll but not enrollable: no employee record, or a contested PIN. */
    public const BLOCKED = 'blocked';

    /** Problems first, then the routine rows; name breaks the tie. */
    private const STATUS_ORDER = [
        self::BLOCKED => 0,
        self::REMOVING => 1,
        self::ADDING => 2,
        self::ON_CLOCK => 3,
    ];

    /**
     * People counts for a list of devices, in a fixed number of queries
     * regardless of how many devices there are (the Devices page renders every
     * clock that has ever checked in).
     *
     * @param  iterable<Device>  $devices
     * @return Collection<string, array{on_clock:int,assigned:int,blocked:int,waiting:int,unconfirmed:int,linked:bool}>
     *                                                                                                                  keyed by device serial
     */
    public static function counts(iterable $devices): Collection
    {
        $devices = collect($devices);
        $serials = $devices->pluck('no_sn')->filter()->values();

        if ($serials->isEmpty()) {
            return collect();
        }

        // Codes, not devices: two physical clocks may be linked to the same
        // payroll device code and then share its assignments.
        $codes = $devices->pluck('payroll_device_code')->filter()->unique()->values();

        $onClock = DeviceEnrollment::whereIn('device_sn', $serials)
            ->selectRaw('device_sn, count(*) as total')
            ->groupBy('device_sn')
            ->pluck('total', 'device_sn');

        // Reported as two numbers, never one. They mean different things and only
        // the first can still be acted on:
        //   pending — never left this server, still cancellable
        //   sent    — the device took it and never reported back
        // Added together and labelled "queued" they read as outstanding work, and
        // a clock that stops confirming carries that inflated number for ever
        // (`sent` rows are deliberately never pruned by age). Observed: a reader
        // showed "1,249 queued" for two days after the last instruction had been
        // delivered, and sent an operator hurrying to cancel nothing.
        $byStatus = DeviceCommand::whereIn('device_sn', $serials)
            ->whereIn('status', ['pending', 'sent'])
            ->selectRaw('device_sn, status, count(*) as total')
            ->groupBy('device_sn', 'status')
            ->get()
            ->groupBy('device_sn');

        $waiting = $byStatus->map(fn ($rows) => (int) $rows->firstWhere('status', 'pending')?->total);
        $unconfirmed = $byStatus->map(fn ($rows) => (int) $rows->firstWhere('status', 'sent')?->total);

        $assigned = $codes->isEmpty() ? collect() : DeviceAssignment::whereIn('device_code', $codes)
            ->selectRaw('device_code, count(*) as total')
            ->groupBy('device_code')
            ->pluck('total', 'device_code');

        // Assigned people with no employee_map row — whereNotExists rather than a
        // pluck()-ed id list, for the same reason EmployeeDirectory uses one: the
        // roster is ~9k rows and that becomes a 9k-item IN clause.
        $blocked = $codes->isEmpty() ? collect() : DeviceAssignment::whereIn('device_code', $codes)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_map')
                ->whereColumn('employee_map.payroll_employee_id', 'device_assignments.payroll_employee_id'))
            ->selectRaw('device_code, count(*) as total')
            ->groupBy('device_code')
            ->pluck('total', 'device_code');

        return $devices->mapWithKeys(fn (Device $d) => [
            $d->no_sn => [
                'on_clock' => (int) ($onClock[$d->no_sn] ?? 0),
                'assigned' => (int) ($assigned[$d->payroll_device_code] ?? 0),
                'blocked' => (int) ($blocked[$d->payroll_device_code] ?? 0),
                'waiting' => (int) ($waiting[$d->no_sn] ?? 0),
                'unconfirmed' => (int) ($unconfirmed[$d->no_sn] ?? 0),
                'linked' => $d->payroll_device_code !== null,
            ],
        ]);
    }

    /**
     * The people on one device, merged from both lists and paginated.
     *
     * Merging happens in PHP rather than SQL: the two sides live in different
     * tables keyed by different things (serial vs payroll device code) and one
     * side has rows the other cannot express at all (an assigned employee with
     * no PIN). A device's roster is bounded by the roster itself (~9k), which
     * is the same order of magnitude EmployeeDirectory already assembles here.
     *
     * @param  array{search?:?string,status?:?string}  $filters
     * @return array{people:LengthAwarePaginator,summary:array{total:int,on_clock:int,adding:int,removing:int,blocked:int,queued:int}}
     */
    public static function forDevice(Device $device, array $filters = [], int $perPage = PerPage::DEFAULT): array
    {
        $people = self::build($device);

        $summary = [
            'total' => $people->count(),
            'on_clock' => $people->where('status', self::ON_CLOCK)->count(),
            'adding' => $people->where('status', self::ADDING)->count(),
            'removing' => $people->where('status', self::REMOVING)->count(),
            'blocked' => $people->where('status', self::BLOCKED)->count(),
            'queued' => $people->whereNotNull('queued')->count(),
        ];

        $filtered = self::filter($people, $filters);

        $page = Paginator::resolveCurrentPage('page');
        $paginator = new LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );

        return ['people' => $paginator, 'summary' => $summary];
    }

    /**
     * One row per person, keyed so the two lists can be merged.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function build(Device $device): Collection
    {
        $enrolled = DeviceEnrollment::where('device_sn', $device->no_sn)->get()->keyBy('pin');

        // An unlinked clock has no assignments to compare against, and the
        // reconciler skips it entirely — so nothing on it is pending removal.
        $assignedIds = $device->payroll_device_code
            ? DeviceAssignment::where('device_code', $device->payroll_device_code)
                ->pluck('payroll_employee_id')
            : collect();

        $assigned = $assignedIds->isEmpty()
            ? collect()
            : EmployeeMap::whereIn('payroll_employee_id', $assignedIds)->get()->keyBy('device_pin');

        $queued = self::queuedByPin($device->no_sn);
        $punches = self::punchesByPin($device->no_sn);

        $rows = [];

        foreach ($assigned as $pin => $employee) {
            $rows[$pin] = self::row(
                pin: $pin,
                status: $enrolled->has($pin) ? self::ON_CLOCK : self::ADDING,
                name: $employee->name,
                chapa: $employee->chapa,
                company: $employee->company,
                payrollId: $employee->payroll_employee_id,
                rfid: $employee->rfid,
                card: RfidConverter::toCard($employee->rfid),
                sentAt: optional($enrolled->get($pin))->updated_at,
            );
        }

        // A `device_enrollment` row carries only what the push protocol needs
        // (PIN, name, card), so anyone reached through it alone would show up
        // with an otherwise blank line. Most of them are still ordinary roster
        // members — someone dropped from a clock is rarely dropped from the
        // company — so fill the rest in from employee_map where it exists, and
        // fall back to the enrollment for the ones genuinely no longer there.
        $leftover = $enrolled->keys()->reject(fn ($pin) => isset($rows[$pin]))->values();
        $leftoverEmployees = $leftover->isEmpty()
            ? collect()
            : EmployeeMap::whereIn('device_pin', $leftover)->get()->keyBy('device_pin');

        foreach ($leftover as $pin) {
            $enrollment = $enrolled->get($pin);
            $employee = $leftoverEmployees->get($pin);

            $rows[$pin] = self::row(
                pin: $pin,
                // On an unlinked clock this person is simply there — there is no
                // assignment list saying otherwise, so calling it a pending
                // removal would invent an intention nothing is acting on.
                status: $device->payroll_device_code ? self::REMOVING : self::ON_CLOCK,
                name: $employee->name ?? $enrollment->name,
                chapa: $employee?->chapa,
                company: $employee?->company,
                payrollId: $employee?->payroll_employee_id,
                rfid: $employee?->rfid,
                card: $enrollment->card,
                sentAt: $enrollment->updated_at,
            );
        }

        // Assigned in payroll but not enrollable — the actionable rows.
        $matched = $assigned->pluck('payroll_employee_id')->map(fn ($id) => (int) $id)->all();
        $unmatched = $assignedIds->map(fn ($id) => (int) $id)->diff($matched);

        if ($unmatched->isNotEmpty()) {
            $contested = PinCollision::pinsByPayrollId($unmatched);

            foreach ($unmatched as $payrollId) {
                $rows['payroll:'.$payrollId] = self::row(
                    pin: $contested[$payrollId] ?? null,
                    status: self::BLOCKED,
                    payrollId: $payrollId,
                    reason: isset($contested[$payrollId])
                        ? 'Two payroll employees claim this device PIN, so it is deliberately unmapped. Decide the owner under Employees > PIN conflicts.'
                        : 'No employee record for this payroll ID on this server. Download employees from DMPI, or check that they are still active in payroll.',
                );
            }
        }

        return collect($rows)
            ->map(function (array $row) use ($queued, $punches) {
                $row['queued'] = $row['pin'] !== null ? ($queued[$row['pin']] ?? null) : null;
                $row['last_punch_at'] = $row['pin'] !== null ? ($punches[$row['pin']]['last_punch_at'] ?? null) : null;
                $row['punch_count'] = $row['pin'] !== null ? (int) ($punches[$row['pin']]['punch_count'] ?? 0) : 0;

                return $row;
            })
            ->sortBy([
                fn (array $a, array $b) => self::STATUS_ORDER[$a['status']] <=> self::STATUS_ORDER[$b['status']],
                fn (array $a, array $b) => strcasecmp((string) ($a['name'] ?? $a['pin']), (string) ($b['name'] ?? $b['pin'])),
            ])
            ->values();
    }

    /** @return array<string, mixed> */
    private static function row(
        ?string $pin,
        string $status,
        ?string $name = null,
        ?string $chapa = null,
        ?string $company = null,
        int|string|null $payrollId = null,
        ?string $rfid = null,
        ?string $card = null,
        $sentAt = null,
        ?string $reason = null,
    ): array {
        return [
            'pin' => $pin,
            'status' => $status,
            'name' => $name,
            'chapa' => $chapa,
            'company' => $company,
            'payroll_employee_id' => $payrollId,
            'rfid' => $rfid,
            'card' => $card,
            'sent_at' => $sentAt,
            'reason' => $reason,
        ];
    }

    /**
     * Changes still sitting in the device's queue, per PIN.
     *
     * Read from the command bodies rather than inferred from the two lists,
     * because "we recorded that we sent it" and "the clock has taken it" are
     * different facts and the gap between them is the whole point of showing
     * this: a reader unplugged for a fortnight has an untouched queue and an
     * enrollment table that claims everything was sent.
     *
     * @return array<string, string> pin => 'add'|'remove'
     */
    private static function queuedByPin(string $deviceSn): array
    {
        $queued = [];

        DeviceCommand::where('device_sn', $deviceSn)
            ->whereIn('status', ['pending', 'sent'])
            ->orderBy('id')
            ->pluck('body')
            ->each(function (?string $body) use (&$queued) {
                if (! preg_match('/PIN=([^\t\r\n]+)/', (string) $body, $m)) {
                    return;
                }
                // Last one wins: the queue is ordered, so an add followed by a
                // remove for the same person ends as a remove.
                $queued[trim($m[1])] = str_contains((string) $body, 'DELETE') ? 'remove' : 'add';
            });

        return $queued;
    }

    /**
     * Punch activity on this clock, per PIN — one grouped query over the
     * device's own rows rather than a whereIn over thousands of PINs.
     *
     * @return array<string, array{last_punch_at:mixed,punch_count:int}>
     */
    private static function punchesByPin(string $deviceSn): array
    {
        return Attendance::where('sn', $deviceSn)
            ->selectRaw('employee_id, count(*) as punch_count, max(timestamp) as last_punch_at')
            ->groupBy('employee_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->employee_id => [
                'last_punch_at' => $row->last_punch_at,
                'punch_count' => (int) $row->punch_count,
            ]])
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $people
     * @param  array{search?:?string,status?:?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private static function filter(Collection $people, array $filters): Collection
    {
        $status = $filters['status'] ?? null;
        if ($status !== null && $status !== '' && array_key_exists($status, self::STATUS_ORDER)) {
            $people = $people->where('status', $status);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $people = $people->filter(function (array $row) use ($needle) {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $row['name'], $row['pin'], $row['chapa'], $row['company'],
                    $row['rfid'], $row['card'], (string) $row['payroll_employee_id'],
                ])));

                return str_contains($haystack, $needle);
            });
        }

        return $people->values();
    }

    /** Plain-language label + Bootstrap badge class for a status. */
    public static function label(string $status): array
    {
        return match ($status) {
            self::ON_CLOCK => ['On the clock', 'bg-success'],
            self::ADDING => ['Waiting to be added', 'bg-info text-dark'],
            self::REMOVING => ['Waiting to be removed', 'bg-warning text-dark'],
            self::BLOCKED => ["Can't be added", 'bg-danger'],
            default => [$status, 'bg-secondary'],
        };
    }
}
