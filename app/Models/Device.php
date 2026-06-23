<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;

    /** A device is considered offline if it has not made contact within this many minutes. */
    public const ONLINE_THRESHOLD_MINUTES = 5;

    protected $fillable = [
        'nama',
        'no_sn',
        'lokasi',
        'direction',
        'online',
        'payroll_device_code',
    ];

    protected $casts = [
        'online' => 'datetime',
    ];

    public function isOnline(): bool
    {
        return $this->online !== null
            && $this->online->gt(now()->subMinutes(self::ONLINE_THRESHOLD_MINUTES));
    }

    public function getStatusAttribute(): string
    {
        return $this->isOnline() ? 'online' : 'offline';
    }
}
