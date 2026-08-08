<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceCommand extends Model
{
    protected $fillable = [
        'device_sn',
        'body',
        'source_command_id',
        'status',
        'return_code',
        'response',
        'verification_status',
        'verification_payload',
        'verified_at',
        'sent_at',
        'done_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'done_at' => 'datetime',
        'verified_at' => 'datetime',
    ];
}
