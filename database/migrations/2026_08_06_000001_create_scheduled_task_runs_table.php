<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per scheduled job run, so "is the scheduler working?" stops being a
 * guess.
 *
 * Before this the only evidence a scheduled job had run was the activity-log
 * line each command happened to write for itself, which meant the answer was
 * only ever as good as the command's own error handling: a job that died before
 * its logging, or was blocked by `withoutOverlapping()`, left nothing at all.
 * Recording from Laravel's own scheduler events instead captures every run from
 * the outside, including the two failures that used to be invisible — a job that
 * started and never finished, and a job silently skipped because the previous
 * run is still going.
 *
 * `status` is the outcome as the *scheduler* saw it:
 *   running    — started, no end recorded yet (or the process died mid-run)
 *   succeeded  — finished, exit code 0
 *   failed     — threw, or finished with a non-zero exit code
 *   skipped    — a filter (`when`/`skip`) said no; the job was never meant to run
 *   overlapping — blocked because the previous run of the same job is still going
 *
 * `overlapping` is deliberately its own status rather than a kind of "skipped".
 * Every job here uses `withoutOverlapping()`, so a job that hangs shows up as one
 * long `running` row followed by a wall of `overlapping` ones — which is the
 * signature of the stuck-job outage that previously looked identical to a
 * stopped scheduler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('command');                    // "payroll:sync-attendances"
            $table->string('status')->default('running');
            // Null while running, and null for a run that never got as far as a
            // process — an overlap-blocked job has no exit code to report.
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('runtime_ms')->nullable();
            // Trimmed at write time: this is a diagnostic tail, not an archive.
            $table->text('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The two reads this table gets: "newest first" for the log page, and
            // "newest run of this one job" for the health card and per-job filter.
            $table->index(['id', 'command']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
