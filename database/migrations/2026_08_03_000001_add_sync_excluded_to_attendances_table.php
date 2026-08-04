<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Manually marked "skip" from the Attendance screen — AttendanceSync's
            // automatic/scheduled sync() skips these, but an operator can still
            // hand-pick one to push via the "sync selected" action, which bypasses
            // this flag on purpose (an explicit pick overrides a standing exclusion).
            $table->boolean('sync_excluded')->default(false)->after('is_sync');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('sync_excluded');
        });
    }
};
