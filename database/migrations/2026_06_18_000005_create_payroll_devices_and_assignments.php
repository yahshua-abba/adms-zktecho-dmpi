<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of DMPI's device list and device-employee assignments (pulled from
 * /api/v2/read_device_info/). `payroll_devices` feeds the device-link dropdown;
 * `device_assignments` tells the reconciler which employees belong on which
 * payroll device (by `code`). Kept on the edge so the reconciler reads only
 * local tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_devices', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('device_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('device_code')->index();
            $table->unsignedBigInteger('payroll_employee_id');
            $table->timestamps();
            $table->unique(['device_code', 'payroll_employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_assignments');
        Schema::dropIfExists('payroll_devices');
    }
};
