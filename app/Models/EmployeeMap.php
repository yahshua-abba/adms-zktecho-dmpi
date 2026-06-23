<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeMap extends Model
{
    protected $table = 'employee_map';

    protected $fillable = [
        'device_pin',
        'chapa',
        'company',
        'payroll_employee_id',
        'name',
        'rfid',
    ];
}
