<?php

use App\Sync\LogType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A punch's IN/OUT is now frozen onto the row at arrival rather than
     * recomputed from the device's (mutable) direction. Backfill existing rows
     * from each device's current direction so history keeps its present meaning;
     * from here on, editing a device's direction only affects future punches.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('log_type', 8)->nullable()->after('status5');
        });

        $directions = DB::table('devices')->pluck('direction', 'no_sn');

        DB::table('attendances')->orderBy('id')->chunkById(1000, function ($rows) use ($directions) {
            foreach ($rows as $row) {
                $direction = $directions[$row->sn] ?? null;
                $logType = LogType::resolve($direction, (int) $row->status1);
                if ($logType !== null) {
                    DB::table('attendances')->where('id', $row->id)->update(['log_type' => $logType]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('log_type');
        });
    }
};
