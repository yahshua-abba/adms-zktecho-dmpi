<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Device User IDs are the composite "{company}_{chapa}" (per the legacy TCD
 * scheme), so the PIN that arrives on a punch is a string with an underscore,
 * not an integer. Widen employee_id to hold it.
 *
 * SQLite uses type affinity: an INTEGER-affinity column transparently stores a
 * non-numeric string as TEXT, so no change is needed there (keeps tests free of
 * a doctrine/dbal dependency).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE attendances MODIFY employee_id VARCHAR(191) NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE attendances MODIFY employee_id INT NOT NULL');
        }
    }
};
