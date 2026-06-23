<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHAPA numbers collide across the manpower companies, so CHAPA alone cannot
 * key the map. Mirror the legacy TCD scheme: the device PIN is the composite
 * "{company}_{chapa}", which IS globally unique — make that the join key.
 *
 * `chapa` and `company` are kept for display/search/filtering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_map', function (Blueprint $table) {
            $table->dropUnique(['chapa']);          // chapa is no longer globally unique
            $table->string('device_pin')->nullable()->after('id'); // = "{company}_{chapa}"
            $table->string('company')->nullable()->after('chapa');
        });

        Schema::table('employee_map', function (Blueprint $table) {
            $table->unique('device_pin');
        });
    }

    public function down(): void
    {
        Schema::table('employee_map', function (Blueprint $table) {
            $table->dropUnique(['device_pin']);
            $table->dropColumn(['device_pin', 'company']);
        });

        Schema::table('employee_map', function (Blueprint $table) {
            $table->unique('chapa');
        });
    }
};
