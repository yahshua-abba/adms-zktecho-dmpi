<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $table = 'activity_log';

    public $timestamps = false; // append-only; only created_at

    protected $fillable = ['level', 'event', 'message', 'context', 'created_at'];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    /** Record a server activity entry. */
    public static function record(string $event, string $message, string $level = 'info', array $context = []): self
    {
        return static::create([
            'event' => $event,
            'message' => $message,
            'level' => $level,
            'context' => $context ?: null,
            'created_at' => now(),
        ]);
    }
}
