<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceEnrollment extends Model
{
    protected $table = 'device_enrollment';

    protected $fillable = [
        'device_sn',
        'pin',
        'name',
        'card',
    ];
}
