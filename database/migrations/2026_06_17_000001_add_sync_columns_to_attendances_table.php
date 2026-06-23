<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('is_sync')->default(false)->index();
            $table->string('sync_id')->nullable()->unique();
            $table->dateTime('sync_time')->nullable();
            $table->text('sync_error')->nullable();
        });

        // Punches that existed before sync was introduced (test/historical rows)
        // must not be replayed to payroll — mark them already synced.
        DB::table('attendances')->update(['is_sync' => true]);
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['is_sync', 'sync_id', 'sync_time', 'sync_error']);
        });
    }
};
