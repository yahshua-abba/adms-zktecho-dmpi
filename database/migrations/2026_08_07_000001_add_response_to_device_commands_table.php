<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            // Keep the exact line the clock posted to /iclock/devicecmd. The
            // return code is useful for filtering, while this is the evidence
            // an operator needs when diagnosing a particular command.
            $table->text('response')->nullable()->after('return_code');
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            $table->dropColumn('response');
        });
    }
};
