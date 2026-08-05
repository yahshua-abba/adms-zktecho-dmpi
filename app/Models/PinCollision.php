<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A device PIN claimed by more than one DMPI employee. See the migration for
 * why these are parked here instead of being written into employee_map.
 */
class PinCollision extends Model
{
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
