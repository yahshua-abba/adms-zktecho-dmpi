<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A punch is uniquely identified by (device, employee PIN, timestamp). The
 * unique index lets ingest use insertOrIgnore to drop duplicate records that
 * a device re-sends (common after it reconnects from an outage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unique(['sn', 'employee_id', 'timestamp'], 'attendances_punch_unique');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('attendances_punch_unique');
        });
    }
};
