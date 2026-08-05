<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One HTTP request to DMPI. Metadata only — see PayrollCallRecorder for why no
 * request or response body is ever stored.
 */
class PayrollCall extends Model
{
    public $timestamps = false; // append-only; only created_at

    protected $fillable = [
        'sync_run_id', 'method', 'endpoint', 'status_code',
        'response_bytes', 'duration_ms', 'outcome', 'error', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'status_code' => 'integer',
        'response_bytes' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function syncRun()
    {
        return $this->belongsTo(SyncRun::class);
    }

    public function succeeded(): bool
    {
        return $this->outcome === 'ok';
    }

    /** Human-sized response, e.g. "42.2 MB". */
    public function size(): string
    {
        $bytes = $this->response_bytes;

        if ($bytes === null) {
            return '—';
        }

        foreach ([['GB', 1073741824], ['MB', 1048576], ['KB', 1024]] as [$unit, $step]) {
            if ($bytes >= $step) {
                return round($bytes / $step, 1).' '.$unit;
            }
        }

        return $bytes.' B';
    }

    public function duration(): string
    {
        if ($this->duration_ms === null) {
            return '—';
        }

        return $this->duration_ms < 1000
            ? $this->duration_ms.' ms'
            : round($this->duration_ms / 1000, 1).' s';
    }
}
