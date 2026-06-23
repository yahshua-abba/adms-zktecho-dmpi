<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server activity log: what ADMS itself does — sync runs, roster/device pulls,
 * enrollment reconciles, and errors. Distinct from the raw device_log/finger_log
 * (which capture device traffic). Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('level')->default('info')->index();   // info | warning | error
            $table->string('event')->index();                    // e.g. attendance.sync, roster.sync
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
