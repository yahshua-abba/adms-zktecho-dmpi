<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A device PIN claimed by more than one DMPI employee. See the migration for
 * why these are parked here instead of being written into employee_map.
 */
class PinCollision extends Model
{
    /**
     * Which contested PIN (if any) each of these payroll employees is caught on.
     *
     * Two screens ask this — the people on a physical clock, and the people
     * assigned to a timekeeper device — and both ask it for the same reason:
     * an employee with no employee_map row is either missing from the roster or
     * deliberately left unmapped by a collision, and only the second of those
     * has a fix an operator can act on. Shared so the two can't drift into
     * telling different stories about the same person.
     *
     * The claimants live in a JSON column, so the match is done in PHP. There is
     * one row per contested PIN and only a handful exist in practice.
     *
     * @param  Collection<int, int>|array<int, int>  $payrollIds
     * @return array<int, string> payroll_employee_id => device_pin
     */
    public static function pinsByPayrollId($payrollIds): array
    {
        $wanted = collect($payrollIds)->map(fn ($id) => (int) $id)->flip();
        $byPayrollId = [];

        static::all()->each(function (self $collision) use ($wanted, &$byPayrollId) {
            foreach ($collision->claimants ?? [] as $claimant) {
                $id = (int) ($claimant['payroll_employee_id'] ?? 0);
                if ($wanted->has($id)) {
                    $byPayrollId[$id] = $collision->device_pin;
                }
            }
        });

        return $byPayrollId;
    }

    protected $table = 'pin_collisions';

    protected $fillable = [
        'device_pin',
        'claimants',
        'resolved_payroll_employee_id',
        'resolved_at',
    ];

    protected $casts = [
        'claimants' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function isResolved(): bool
    {
        return $this->resolved_payroll_employee_id !== null;
    }

    /** The claimant the operator picked, or null while still contested. */
    public function resolvedClaimant(): ?array
    {
        foreach ($this->claimants ?? [] as $claimant) {
            if ((int) ($claimant['payroll_employee_id'] ?? 0) === (int) $this->resolved_payroll_employee_id) {
                return $claimant;
            }
        }

        return null;
    }
}
