<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee's RFID card (from DMPI), pushed to the device's Card field so a
 * card tap maps to the right user. Stored as received from DMPI; converted to
 * the device's decimal Card format at push time (App\Sync\RfidConverter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_map', function (Blueprint $table) {
            $table->string('rfid')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_map', function (Blueprint $table) {
            $table->dropColumn('rfid');
        });
    }
};
