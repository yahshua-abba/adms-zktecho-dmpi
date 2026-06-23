<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound command queue for the ADMS push protocol. The device polls
 * GET /iclock/getrequest; we hand it pending commands as "C:<id>:<body>" lines
 * and mark them sent. The device reports the result to POST /iclock/devicecmd,
 * which flips status to done/failed with the return code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->string('device_sn')->index();
            $table->text('body');                       // e.g. "DATA UPDATE USERINFO PIN=..."
            $table->string('status')->default('pending')->index(); // pending|sent|done|failed
            $table->integer('return_code')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('done_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
