<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\AbsensiSholatController;
use App\Http\Controllers\iclockController;


// Combined "Monitoring" page (stats + health). Dashboard/Health kept as
// redirects so existing links and route() references stay valid.
Route::get('monitoring', [MonitoringController::class, 'index'])->name('monitoring');
Route::get('dashboard', fn () => redirect()->route('monitoring'))->name('dashboard');
Route::get('health', fn () => redirect()->route('monitoring'))->name('health');
Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
Route::view('help', 'help')->name('help');
Route::get('healthz', [HealthController::class, 'json'])->name('health.json');
Route::post('scheduler/start', [HealthController::class, 'startScheduler'])->name('scheduler.start');
Route::get('devices', [DeviceController::class, 'Index'])->name('devices.index');
Route::get('devices-status', [DeviceController::class, 'status'])->name('devices.status');
Route::patch('devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
Route::post('devices/{device}/sync-enrollments', [DeviceController::class, 'syncEnrollments'])->name('devices.syncEnrollments');
Route::get('devices/{device}/logs', [DeviceController::class, 'DevicePunchLog'])->name('devices.PunchLog');
Route::get('devices-log', [DeviceController::class, 'DeviceLog'])->name('devices.DeviceLog');
Route::get('finger-log', [DeviceController::class, 'FingerLog'])->name('devices.FingerLog');
Route::get('attendance', [DeviceController::class, 'Attendance'])->name('devices.Attendance');
Route::get('attendance/export', [DeviceController::class, 'exportAttendance'])->name('attendance.export');
Route::post('attendance/sync', [DeviceController::class, 'syncAttendances'])->name('attendance.sync');
Route::post('dmpi/sync', [DeviceController::class, 'syncFromDmpi'])->name('dmpi.sync');


// handshake
Route::get('/iclock/cdata', [iclockController::class, 'handshake']);
// request dari device
Route::post('/iclock/cdata', [iclockController::class, 'receiveRecords']);

Route::get('/iclock/test', [iclockController::class, 'test']);
Route::get('/iclock/getrequest', [iclockController::class, 'getrequest']);
// device reports command execution results
Route::post('/iclock/devicecmd', [iclockController::class, 'devicecmd']);



Route::get('/', function () {
    return redirect()->route('monitoring');
});
