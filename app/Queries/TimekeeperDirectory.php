<?php

namespace App\Queries;

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\EmployeeMap;
use App\Models\PayrollDevice;
use App\Models\PinCollision;
use App\Support\PerPage;
use App\Sync\RfidConverter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginated;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * DMPI's timekeeper devices and the people it assigns to each — payroll's own
 * view of the estate, rather than this server's.
 *
 * The distinction from DeviceRoster is the whole point. DeviceRoster answers
 * "who is on this reader", and can only be asked about a physical clock that is
 * already linked to a payroll device code. This answers "who would be on this
 * door", for all of DMPI's devices, linked or not — which is the question you
 * have *before* choosing a link, and the one nothing could answer before: a
 * live reader could be pointed at an empty test entry and quietly emptied,
 * because the only way to see what was behind that entry was to commit to it.
 *
 * Read-only over tables the DMPI pull already fills (`payroll_devices`,
 * `device_assignments`, `employee_map`). It never calls payroll.
 *
 * Deliberately NOT reported here: whether each person is actually on a machine.
 * That is a fact about one physical reader, held in `device_enrollment` and
 * `device_commands`, and a payroll device may have two readers linked to it or
 * none. Merging them would invent a single "enrolled" answer where there are
 * zero or two — so the detail screen links out to each linked reader's People
 * page instead, and keeps that fact where it belongs.
 */
class TimekeeperDirectory
{
    /** Assigned in payroll and resolvable to a device PIN — this person can be enrolled. */
    public const ENROLLABLE = 'enrollable';

    /** Assigned in payroll but with no employee_map row — cannot be enrolled at all. */
    public const BLOCKED = 'blocked';

    /** Filter values for the device list. */
    public const FILTERS = [
        'linked' => 'Linked to a clock here',
        'unlinked' => 'Not linked to any clock',
        'populated' => 'Has people assigned',
        'empty' => 'Nobody assigned',
    ];

    /**
     * DMPI's device list, each with how many people it puts on the door and how
     * many of those this server could actually enrol.
     *
     * Counted in a fixed number of queries for the page, not per row — the list
     * runs to ~90 devices and the assignment table to the size of the roster.
     *
     * @param  array{search?:?string,filter?:?string}  $filters
     */
    public static function devices(array $filters = [], int $perPage = PerPage::DEFAULT): LengthAwarePaginator
    {
        $query = PayrollDevice::query();

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(fn (Builder $q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }

        // Applied in SQL rather than after paginating, or "3 unlinked" would mean
        // "3 on this page" and the count in the footer would contradict it.
        match ($filters['filter'] ?? null) {
            'linked' => $query->whereExists(self::linkedReaderExists()),
            'unlinked' => $query->whereNotExists(self::linkedReaderExists()),
            'populated' => $query->whereExists(self::assignmentExists()),
            'empty' => $query->whereNotExists(self::assignmentExists()),
            default => null,
        };

        $devices = $query->orderBy('code')->paginate($perPage);

        $codes = $devices->pluck('code');
        if ($codes->isEmpty()) {
            return $devices;
        }

        $assigned = DeviceAssignment::whereIn('device_code', $codes)
            ->selectRaw('device_code, count(*) as total')
            ->groupBy('device_code')
            ->pluck('total', 'device_code');

        // Correlated subquery rather than a pluck()-ed id list, for the same
        // reason EmployeeDirectory uses one: the roster is ~9k rows.
        $blocked = DeviceAssignment::whereIn('device_code', $codes)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_map')
                ->whereColumn('employee_map.payroll_employee_id', 'device_assignments.payroll_employee_id'))
            ->selectRaw('device_code, count(*) as total')
            ->groupBy('device_code')
            ->pluck('total', 'device_code');

        // `id` is in the select because the list links each reader to its People
        // screen, which is keyed by id — without it the link cannot be built and
        // the page dies rather than degrading.
        $readers = Device::whereIn('payroll_device_code', $codes)
            ->get(['id', 'no_sn', 'nama', 'lokasi', 'payroll_device_code'])
            ->groupBy('payroll_device_code');

        $devices->each(function (PayrollDevice $pd) use ($assigned, $blocked, $readers) {
            $total = (int) ($assigned[$pd->code] ?? 0);
            $cannot = (int) ($blocked[$pd->code] ?? 0);

            $pd->setAttribute('assigned', $total);
            $pd->setAttribute('blocked', $cannot);
            $pd->setAttribute('enrollable', $total - $cannot);
            $pd->setAttribute('readers', ($readers->get($pd->code) ?? collect())->values());
        });

        return $devices;
    }

    /** Totals across every DMPI device, not just the page being shown. */
    public static function summary(): array
    {
        $linked = Device::whereNotNull('payroll_device_code')->distinct()->pluck('payroll_device_code');

        return [
            'devices' => PayrollDevice::count(),
            'linked' => PayrollDevice::whereIn('code', $linked)->count(),
            'assignments' => DeviceAssignment::count(),
            'empty' => PayrollDevice::whereNotExists(self::assignmentExists())->count(),
        ];
    }

    /**
     * The people DMPI assigns to one timekeeper device.
     *
     * Built in PHP and paginated by hand for the same reason DeviceRoster does
     * it: rows come from two places that a join can't merge — an assignment with
     * an employee_map row, and an assignment with none, which has no PIN, name
     * or card to select at all and exists only as a payroll id plus a reason.
     * The largest door here assigns ~1,700 people, the same order the Employees
     * screen already assembles.
     *
     * @param  array{search?:?string,status?:?string}  $filters
     * @return array{people:LengthAwarePaginator,summary:array{assigned:int,enrollable:int,blocked:int}}
     */
    public static function people(string $code, array $filters = [], int $perPage = PerPage::DEFAULT): array
    {
        $rows = self::buildPeople($code);

        $summary = [
            'assigned' => $rows->count(),
            'enrollable' => $rows->where('status', self::ENROLLABLE)->count(),
            'blocked' => $rows->where('status', self::BLOCKED)->count(),
        ];

        $filtered = self::filterPeople($rows, $filters);

        $page = Paginator::resolveCurrentPage('page');
        $paginator = new Paginated(
            $filtered->forPage($page, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );

        return ['people' => $paginator, 'summary' => $summary];
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function buildPeople(string $code): Collection
    {
        $assignedIds = DeviceAssignment::where('device_code', $code)->pluck('payroll_employee_id');

        if ($assignedIds->isEmpty()) {
            return collect();
        }

        $employees = EmployeeMap::whereIn('payroll_employee_id', $assignedIds)
            ->get()
            ->keyBy('payroll_employee_id');

        // Only looked up for the ones that failed to resolve, so a healthy device
        // never pays for the collision table at all.
        $unmatched = $assignedIds->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $employees->has($id));

        $contested = $unmatched->isEmpty() ? [] : PinCollision::pinsByPayrollId($unmatched);

        return $assignedIds
            ->map(function ($payrollId) use ($employees, $contested) {
                $employee = $employees->get((int) $payrollId);

                if ($employee !== null) {
                    return [
                        'payroll_employee_id' => (int) $payrollId,
                        'status' => self::ENROLLABLE,
                        'name' => $employee->name,
                        'pin' => $employee->device_pin,
                        'company' => $employee->company,
                        'chapa' => $employee->chapa,
                        'rfid' => $employee->rfid,
                        'card' => RfidConverter::toCard($employee->rfid),
                        'reason' => null,
                    ];
                }

                return [
                    'payroll_employee_id' => (int) $payrollId,
                    'status' => self::BLOCKED,
                    'name' => null,
                    'pin' => $contested[(int) $payrollId] ?? null,
                    'company' => null,
                    'chapa' => null,
                    'rfid' => null,
                    'card' => null,
                    'reason' => isset($contested[(int) $payrollId])
                        ? 'Two payroll employees claim this device PIN, so it is deliberately unmapped. Decide the owner under Employees > PIN conflicts.'
                        : 'No employee record for this payroll ID on this server. Download employees from DMPI, or check that they are still active in payroll.',
                ];
            })
            // Blocked first: they are the only rows with something to do about them.
            ->sortBy([
                fn (array $a, array $b) => ($a['status'] === self::BLOCKED ? 0 : 1) <=> ($b['status'] === self::BLOCKED ? 0 : 1),
                fn (array $a, array $b) => strcasecmp(
                    (string) ($a['name'] ?? $a['payroll_employee_id']),
                    (string) ($b['name'] ?? $b['payroll_employee_id']),
                ),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $people
     * @param  array{search?:?string,status?:?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private static function filterPeople(Collection $people, array $filters): Collection
    {
        $status = $filters['status'] ?? null;
        if (in_array($status, [self::ENROLLABLE, self::BLOCKED], true)) {
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

    /** Plain-language label + Bootstrap badge class for a person's status. */
    public static function label(string $status): array
    {
        return match ($status) {
            self::ENROLLABLE => ['Eligible for enrollment', 'bg-success'],
            self::BLOCKED => ["Can't be enrolled", 'bg-danger'],
            default => [$status, 'bg-secondary'],
        };
    }

    /** A physical reader on this box pointed at the payroll device being filtered. */
    private static function linkedReaderExists(): \Closure
    {
        return fn ($q) => $q->selectRaw('1')->from('devices')
            ->whereColumn('devices.payroll_device_code', 'payroll_devices.code');
    }

    /** At least one employee assigned to the payroll device being filtered. */
    private static function assignmentExists(): \Closure
    {
        return fn ($q) => $q->selectRaw('1')->from('device_assignments')
            ->whereColumn('device_assignments.device_code', 'payroll_devices.code');
    }
}
