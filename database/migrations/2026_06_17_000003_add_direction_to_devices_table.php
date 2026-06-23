<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devices are dedicated IN-only or OUT-only. The punch direction (log_type
     * sent to payroll) is derived from which device recorded the tap, not from
     * the device status codes. `direction` is "in", "out", or null (unconfigured).
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('direction')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }
};
