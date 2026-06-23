<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks what ADMS has pushed to each device (the intended user list per
 * device). The reconciler diffs the desired set (assigned employees) against
 * this to decide what to add/update/delete, and keeps it in step with the
 * assignments. Unique per (device_sn, pin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_enrollment', function (Blueprint $table) {
            $table->id();
            $table->string('device_sn');
            $table->string('pin');     // composite "{company}_{chapa}"
            $table->string('name')->nullable();
            $table->string('card')->nullable();
            $table->timestamps();
            $table->unique(['device_sn', 'pin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_enrollment');
    }
};
