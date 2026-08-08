<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            $table->unsignedBigInteger('source_command_id')->nullable()->index()->after('body');
            $table->string('verification_status')->nullable()->after('response');
            $table->text('verification_payload')->nullable()->after('verification_status');
            $table->dateTime('verified_at')->nullable()->after('verification_payload');
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            $table->dropColumn(['source_command_id', 'verification_status', 'verification_payload', 'verified_at']);
        });
    }
};
