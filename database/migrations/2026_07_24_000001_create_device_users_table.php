<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User records read back from a physical device.
 *
 * This is intentionally separate from device_enrollment, which tracks the
 * users ADMS intends to push. A lookup can discover a locally-created user
 * that is not in the payroll roster yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_users', function (Blueprint $table) {
            $table->id();
            $table->string('device_sn');
            $table->string('pin');
            $table->string('name')->nullable();
            $table->string('card')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['device_sn', 'pin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_users');
    }
};
