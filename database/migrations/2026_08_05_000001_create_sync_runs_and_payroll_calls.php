<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visibility for downloads that can take ten minutes and used to show nothing
 * at all between "requested" and "failed".
 *
 * `sync_runs` is one row per download, updated as it moves through its stages,
 * so the dashboard can say what is happening right now instead of looking idle.
 * It carries the OS pid because stopping a run means killing the process: the
 * expensive part is a single blocking HTTP read, so a cooperative "please stop"
 * flag would only be noticed once the thing you wanted to interrupt had already
 * finished.
 *
 * `payroll_calls` is one row per HTTP request to DMPI — endpoint, how long, what
 * came back. Deliberately metadata only: request bodies are never stored, since
 * the login body carries the payroll password and the safest redaction is not
 * having the data in the first place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('part');                       // employees|devices|assignments|all
            $table->string('status')->default('running'); // running|succeeded|failed|cancelled
            $table->string('stage')->nullable();          // human-readable: "Waiting for DMPI to answer"
            // Null while the total is genuinely unknowable — you cannot show a
            // percentage of a response that has not arrived yet.
            $table->unsignedInteger('done')->nullable();
            $table->unsignedInteger('total')->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        Schema::create('payroll_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->nullable()->index();
            $table->string('method', 10);
            $table->string('endpoint');                   // path only, never the query or body
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedBigInteger('response_bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('outcome');                    // ok|http_error|failed
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_calls');
        Schema::dropIfExists('sync_runs');
    }
};
