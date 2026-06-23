<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a physical device (known by serial `no_sn`) to its DMPI payroll device
 * record (known by `code`). DMPI has no serial field, so this association is
 * set in the ADMS UI and drives which employees get auto-enrolled on the device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('payroll_device_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('payroll_device_code');
        });
    }
};
