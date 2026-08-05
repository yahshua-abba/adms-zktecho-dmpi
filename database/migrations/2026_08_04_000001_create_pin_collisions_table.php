<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device PINs claimed by more than one DMPI employee.
 *
 * `employee_map` is keyed by device_pin and its whole value is that a PIN
 * resolves to exactly one payroll employee. DMPI's live data violates that:
 * "{company}_{chapa}" is not actually unique there (observed: 271_14257 and
 * 271_14598, each claimed by two employees). RosterSync used to write both and
 * let the last one win, so a punch on a contested PIN was filed against
 * whichever employee happened to come last in the download.
 *
 * A punch carries only the PIN, so nothing on the edge can tell the claimants
 * apart — the fix is to stop guessing, not to guess better. Contested PINs are
 * parked here instead of being written to employee_map, which leaves them
 * unresolvable at push time and therefore unsynced-with-a-reason (the existing
 * path for an unmapped PIN) rather than mis-attributed.
 *
 * `resolved_payroll_employee_id` is an operator's decision from the Employees
 * screen: it survives later roster pulls, so resolving once is enough, and it
 * is re-validated against the current claimants each sync (a resolution that
 * no longer matches any claimant is dropped rather than trusted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pin_collisions', function (Blueprint $table) {
            $table->id();
            $table->string('device_pin')->unique();
            // [{payroll_employee_id, name, chapa, company, rfid}, ...] as of the last sync.
            $table->json('claimants');
            $table->unsignedBigInteger('resolved_payroll_employee_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pin_collisions');
    }
};
