<?php

namespace App\Sync;

use App\Contracts\PayrollClient;
use App\Exceptions\EmptyDeviceInfoException;
use App\Models\DeviceAssignment;
use App\Models\PayrollDevice;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors DMPI's device list and device-employee assignments into local tables
 * so the enrollment reconciler reads only edge-side data. Assignments are
 * fully replaced each run so removals in DMPI propagate (the reconciler then
 * deletes those users from the device) — except on a wholly empty payload,
 * which is refused because it is the signature of a failed call rather than a
 * real state. See fetch().
 *
 * The two halves can be applied separately (syncDevices / syncAssignments)
 * because they carry very different risk: refreshing the device list upserts a
 * few dozen rows and disturbs nothing, while refreshing assignments replaces
 * the whole table and therefore drives users on and off every clock. They still
 * arrive in ONE DMPI response — read_device_info returns both and takes no
 * parameter to narrow it — so applying one half does not make the call cheaper,
 * it only limits what gets written.
 */
class DeviceInfoSync
{
    /** Rows per bulk write. */
    private const CHUNK = 500;

    public function __construct(private PayrollClient $payroll) {}

    /**
     * Device list + assignments, the whole picture.
     *
     * @param  ?callable  $progress  fn(string $stage, ?int $done, ?int $total)
     */
    public function sync(?callable $progress = null): void
    {
        $report = $progress ?? fn () => null;
        [$devices, $assignments] = $this->fetch($report);

        DB::transaction(function () use ($devices, $assignments, $report) {
            $this->applyDevices($devices, $report);
            $this->applyAssignments($assignments, $report);
        });
    }

    /** Just the clock list. Never touches assignments. */
    public function syncDevices(?callable $progress = null): int
    {
        $report = $progress ?? fn () => null;
        [$devices] = $this->fetch($report);

        DB::transaction(fn () => $this->applyDevices($devices, $report));

        return count($devices);
    }

    /** Just who belongs on which clock. Replaces the whole assignment table. */
    public function syncAssignments(?callable $progress = null): int
    {
        $report = $progress ?? fn () => null;
        [, $assignments] = $this->fetch($report);

        DB::transaction(fn () => $this->applyAssignments($assignments, $report));

        return count($assignments);
    }

    /**
     * One call to DMPI, validated.
     *
     * A wholly empty payload is the signature of a failed call, not of a real
     * DMPI state — no site has zero devices *and* zero assignments. DMPI's own
     * v2 views confirm it: every exception in them is swallowed and answered
     * with an empty list and HTTP 200, so "empty" really does mean "broke".
     * Replacing on it would delete every assignment and hand the reconciler an
     * empty roster, which unenrolls every user from every device. An empty
     * assignment list alongside real devices is legitimate ("nobody assigned
     * yet") and still replaces, so removals propagate.
     *
     * @return array{0: array<int, array>, 1: array<int, array>}
     */
    private function fetch(?callable $report = null): array
    {
        $report ??= fn () => null;

        // No counts here on purpose: this is one blocking request, and there is no
        // honest percentage of a reply that has not arrived.
        $report('Asking DMPI for devices and assignments', null, null);
        $info = $this->payroll->fetchDeviceInfo();
        $devices = $info['devices'] ?? [];
        $assignments = $info['assignments'] ?? [];

        if ($devices === [] && $assignments === []) {
            throw new EmptyDeviceInfoException(
                'DMPI returned no devices and no assignments; refusing to replace local assignments. '
                .'Check Server Activity for a payroll login failure.'
            );
        }

        return [$devices, $assignments];
    }

    /** @param  array<int, array>  $devices */
    private function applyDevices(array $devices, ?callable $report = null): void
    {
        $report ??= fn () => null;
        $report('Saving the clock list', 0, count($devices));
        $now = now();
        $rows = [];

        foreach ($devices as $device) {
            $rows[] = [
                'code' => $device['code'],
                'name' => $device['name'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            PayrollDevice::upsert($chunk, ['code'], ['name', 'updated_at']);
        }
    }

    /** @param  array<int, array>  $assignments */
    private function applyAssignments(array $assignments, ?callable $report = null): void
    {
        $report ??= fn () => null;
        $report('Sorting '.count($assignments).' assignments', null, null);
        $now = now();

        // The payload can list the same (device, employee) pair twice. The table
        // has a unique index on that pair, so a plain insert would fail where the
        // old per-row updateOrCreate quietly collapsed the repeat.
        $seen = [];
        $rows = [];
        foreach ($assignments as $assignment) {
            $key = $assignment['device_code'].'|'.$assignment['employee_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $rows[] = [
                'device_code' => $assignment['device_code'],
                'payroll_employee_id' => $assignment['employee_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Replace wholesale. Nothing survives the delete, so these are plain
        // inserts — the old updateOrCreate asked "does this row exist?" once per
        // assignment against a table it had just emptied.
        DeviceAssignment::query()->delete();

        // Countable, so the percentage here is real.
        $total = count($rows);
        $written = 0;
        $report('Saving assignments', 0, $total);

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DeviceAssignment::insert($chunk);

            $written += count($chunk);
            $report('Saving assignments', $written, $total);
        }
    }
}
