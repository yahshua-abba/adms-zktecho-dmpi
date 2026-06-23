<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Maps a device PIN to a DMPI payroll employee.
     *
     * Per the integration decision, devices are enrolled with User ID = CHAPA No.
     * (DMPI's `employeeid`), so `chapa` IS the device PIN. It is the company-wide
     * employee identifier, so the map is device-independent.
     */
    public function up(): void
    {
        Schema::create('employee_map', function (Blueprint $table) {
            $table->id();
            $table->string('chapa')->unique();        // device PIN == DMPI employeeid
            $table->unsignedBigInteger('payroll_employee_id'); // DMPI Employee.id
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_map');
    }
};
