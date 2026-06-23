<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the dashboard filters. Without these, date/device/employee
 * filtering full-scans the table — fine at tens of thousands of rows, but
 * degrades into the hundreds of thousands. Paired with range-based date
 * queries (not whereDate, which wraps the column and defeats the index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->index('timestamp');
            $table->index('sn');
            $table->index('employee_id');
        });

        Schema::table('device_log', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('sn');
        });

        Schema::table('finger_log', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['timestamp']);
            $table->dropIndex(['sn']);
            $table->dropIndex(['employee_id']);
        });

        Schema::table('device_log', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['sn']);
        });

        Schema::table('finger_log', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
