<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceCommand extends Model
{
    protected $fillable = [
        'device_sn',
        'body',
        'status',
        'return_code',
        'sent_at',
        'done_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'done_at' => 'datetime',
    ];
}
