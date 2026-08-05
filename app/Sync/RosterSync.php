<?php

namespace App\Sync;

use App\Contracts\PayrollClient;
use App\Exceptions\EmptyRosterException;
use App\Models\EmployeeMap;
use App\Models\PinCollision;

/**
 * Pulls the employee roster from DMPI and upserts the device-PIN -> payroll-id
 * map. Devices are enrolled with User ID = "{company}_{chapa}" (the legacy TCD
 * scheme), so that composite IS the device PIN and the map's join key — CHAPA
 * alone collides across the manpower companies.
 *
 * That composite was assumed globally unique. In DMPI's live data it isn't, so
 * this also splits the roster into PINs claimed by exactly one employee (which
 * map normally) and PINs claimed by several (parked in `pin_collisions` and
 * deliberately left unmapped — see recordCollisions()).
 *
 * Writes go out in chunks rather than a row at a time: the roster is ~9k people
 * and updateOrCreate cost two queries each, which made saving the roster slower
 * than downloading it.
 */
class RosterSync
{
    /** Rows per bulk write. Keeps the statement well under any placeholder cap. */
    private const CHUNK = 500;

    public function __construct(private PayrollClient $payroll) {}

    /**
     * @param  ?callable  $progress  fn(string $stage, ?int $done, ?int $total) — lets a
     *                               caller show what is happening. Note the fetch
     *                               reports no counts: it is one blocking request, so
     *                               there is no honest percentage to give until the
     *                               reply arrives.
     * @return array{mapped:int, contested:int, resolved:int}
     */
    public function sync(?callable $progress = null): array
    {
        $report = $progress ?? fn () => null;

        $report('Asking DMPI for the employee list', null, null);
        $employees = $this->payroll->fetchEmployees();

        // This run deletes state on the strength of the payload (stale collisions,
        // mappings that became contested), so refuse to act on an empty one.
        if ($employees === []) {
            throw new EmptyRosterException(
                'DMPI returned no employees; refusing to reconcile the roster against an empty payload. '
                .'Check Server Activity for a payroll login failure.'
            );
        }

        $report('Checking '.count($employees).' employees for PIN conflicts', null, null);

        $claimantsByPin = [];
        foreach ($employees as $employee) {
            $claimantsByPin[$employee['company'].'_'.$employee['chapa']][] = $employee;
        }

        $single = [];
        $contested = [];
        foreach ($claimantsByPin as $pin => $claimants) {
            if (count($claimants) === 1) {
                $single[$pin] = $claimants[0];
            } else {
                $contested[$pin] = $claimants;
            }
        }

        $resolved = $this->recordCollisions($contested);

        // Union, not array_merge: the keys are device PINs and must be preserved.
        // The two sets are disjoint by construction.
        $this->writeMappings($single + $resolved, $report);

        // A PIN that is contested and undecided must resolve to nobody — drop the
        // row an earlier last-write-wins run left behind, so its punches fall into
        // the existing "unmapped PIN" path instead of the wrong employee's record.
        $this->dropMappings(array_values(array_diff(array_keys($contested), array_keys($resolved))));

        return [
            'mapped' => count($single) + count($resolved),
            'contested' => count($contested),
            'resolved' => count($resolved),
        ];
    }

    /**
     * Park contested PINs, forget the ones that cleared up, and hand back those an
     * operator has already decided so they map normally again.
     *
     * A standing decision is re-validated against who is claiming the PIN *now*:
     * DMPI may have replaced or removed a claimant since it was made, and an
     * operator's pick for an employee who no longer claims the PIN is not a
     * decision about the current conflict.
     *
     * Collisions number in the handful, so these stay row-at-a-time.
     *
     * @param  array<string, array<int, array>>  $contested  pin => claimants
     * @return array<string, array> pin => the employee the operator picked
     */
    private function recordCollisions(array $contested): array
    {
        // No longer claimed twice => no longer a collision. Dropping the row drops
        // the resolution with it, so a future re-collision is decided afresh.
        PinCollision::whereNotIn('device_pin', array_keys($contested))->delete();

        if ($contested === []) {
            return [];
        }

        $existing = PinCollision::whereIn('device_pin', array_keys($contested))->get()->keyBy('device_pin');
        $resolved = [];

        foreach ($contested as $pin => $claimants) {
            $row = $existing->get($pin) ?? new PinCollision(['device_pin' => $pin]);

            $row->claimants = array_map(fn (array $employee) => [
                'payroll_employee_id' => (int) $employee['id'],
                'name' => $employee['name'] ?? null,
                'company' => (string) $employee['company'],
                'chapa' => (string) $employee['chapa'],
                'rfid' => $employee['rfid'] ?? null,
            ], array_values($claimants));

            $pick = null;
            if ($row->resolved_payroll_employee_id !== null) {
                foreach ($claimants as $claimant) {
                    if ((int) $claimant['id'] === (int) $row->resolved_payroll_employee_id) {
                        $pick = $claimant;
                        break;
                    }
                }

                if ($pick === null) {
                    $row->resolved_payroll_employee_id = null;
                    $row->resolved_at = null;
                }
            }

            $row->save();

            if ($pick !== null) {
                $resolved[$pin] = $pick;
            }
        }

        return $resolved;
    }

    /** @param  array<string, array>  $employees  pin => employee */
    private function writeMappings(array $employees, ?callable $report = null): void
    {
        $report ??= fn () => null;
        $now = now();
        $rows = [];

        foreach ($employees as $pin => $employee) {
            $rows[] = [
                'device_pin' => $pin,
                'company' => (string) $employee['company'],
                'chapa' => (string) $employee['chapa'],
                'payroll_employee_id' => $employee['id'],
                'name' => $employee['name'] ?? null,
                'rfid' => $employee['rfid'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Here a percentage IS honest — we know how many rows there are.
        $total = count($rows);
        $written = 0;
        $report('Saving employees', 0, $total);

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            // created_at is intentionally absent from the update list so an existing
            // row keeps the date it was first mapped.
            EmployeeMap::upsert(
                $chunk,
                ['device_pin'],
                ['company', 'chapa', 'payroll_employee_id', 'name', 'rfid', 'updated_at'],
            );

            $written += count($chunk);
            $report('Saving employees', $written, $total);
        }
    }

    /** @param  string[]  $pins */
    private function dropMappings(array $pins): void
    {
        foreach (array_chunk($pins, self::CHUNK) as $chunk) {
            EmployeeMap::whereIn('device_pin', $chunk)->delete();
        }
    }
}
