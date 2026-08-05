<?php

use App\Http\Controllers\ActivityController;
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
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\iclockController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\SchedulerLogController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SyncStatusController;
use Illuminate\Support\Facades\Route;

// Login: no users table, just the single .env-configured admin (see
// config('adms.auth') / RequireAdminLogin). Throttled against brute force.
Route::get('login', [AuthController::class, 'showLogin'])->name('login');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.attempt');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

// /healthz stays open — it's the machine-readable endpoint external uptime
// monitors poll, not something an operator browses to.
Route::get('healthz', [HealthController::class, 'json'])->name('health.json');

// Everything below is the dashboard proper — gated behind the admin login.
Route::middleware('auth.admin')->group(function () {
    // Combined "Monitoring" page (stats + health). Dashboard/Health kept as
    // redirects so existing links and route() references stay valid.
    Route::get('monitoring', [MonitoringController::class, 'index'])->name('monitoring');
    Route::get('dashboard', fn () => redirect()->route('monitoring'))->name('dashboard');
    Route::get('health', fn () => redirect()->route('monitoring'))->name('health');
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    // Deciding who owns a device PIN two payroll employees both claim.
    Route::post('employees/conflicts/{collision}/resolve', [EmployeeController::class, 'resolveCollision'])->name('employees.conflicts.resolve');
    Route::post('employees/conflicts/{collision}/clear', [EmployeeController::class, 'clearCollision'])->name('employees.conflicts.clear');
    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
    Route::view('help', 'help')->name('help');
    Route::get('settings', [SettingsController::class, 'index'])->name('settings');
    // Throttled like the login: it takes the admin password, so it must not be a
    // place to guess it. Nothing else in the app destroys data this way.
    Route::post('settings/clear-database', [SettingsController::class, 'clearDatabase'])
        ->middleware('throttle:5,1')
        ->name('settings.clear-database');
    Route::post('scheduler/start', [HealthController::class, 'startScheduler'])->name('scheduler.start');
    // What the background scheduler has been doing — one row per job run.
    Route::get('scheduler-log', [SchedulerLogController::class, 'index'])->name('scheduler.log');
    Route::get('devices', [DeviceController::class, 'Index'])->name('devices.index');
    Route::get('devices-status', [DeviceController::class, 'status'])->name('devices.status');
    Route::patch('devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
    // Removing a clock keeps its punches — see DeviceController::destroy.
    Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');
    Route::post('devices/{device}/sync-enrollments', [DeviceController::class, 'syncEnrollments'])->name('devices.syncEnrollments');
    // Who is on one clock — payroll's assignments and what this server has sent,
    // side by side. See App\Queries\DeviceRoster.
    Route::get('devices/{device}/people', [DeviceController::class, 'people'])->name('devices.people');
    Route::get('devices/{device}/logs', [DeviceController::class, 'DevicePunchLog'])->name('devices.PunchLog');
    Route::get('devices-log', [DeviceController::class, 'DeviceLog'])->name('devices.DeviceLog');
    Route::get('finger-log', [DeviceController::class, 'FingerLog'])->name('devices.FingerLog');
    Route::get('attendance', [DeviceController::class, 'Attendance'])->name('devices.Attendance');
    Route::get('attendance/export', [DeviceController::class, 'exportAttendance'])->name('attendance.export');
    Route::post('attendance/sync', [DeviceController::class, 'syncAttendances'])->name('attendance.sync');
    Route::post('attendance/sync-selected', [DeviceController::class, 'syncSelectedAttendances'])->name('attendance.sync-selected');
    Route::post('attendance/exclude', [DeviceController::class, 'excludeAttendances'])->name('attendance.exclude');
    Route::post('attendance/delete', [DeviceController::class, 'destroyAttendances'])->name('attendance.delete');
    // One button per thing you can download: employees, the clock list, and who
    // belongs on which clock. The last two come from the same DMPI call but are
    // applied separately — see App\Sync\DeviceInfoSync.
    Route::post('dmpi/sync/{part}', [DeviceController::class, 'syncFromDmpi'])
        ->whereIn('part', ['employees', 'devices', 'assignments'])
        ->name('dmpi.sync');

    // Live progress + stop. Kept off the /dmpi/sync/{part} path so a verb here can
    // never be mistaken for a thing to download.
    Route::get('dmpi/status', [SyncStatusController::class, 'status'])->name('dmpi.status');
    Route::post('dmpi/stop', [SyncStatusController::class, 'stop'])->name('dmpi.stop');
    Route::get('payroll-calls', [SyncStatusController::class, 'calls'])->name('dmpi.calls');

    Route::get('/', function () {
        return redirect()->route('monitoring');
    });
});

// Device push-protocol endpoints (/iclock/*) — intentionally open + CSRF-exempt
// (see App\Http\Middleware\VerifyCsrfToken) so physical devices can post
// without a login. Never put these behind auth.admin.
Route::get('/iclock/cdata', [iclockController::class, 'handshake']);
Route::post('/iclock/cdata', [iclockController::class, 'receiveRecords']);
Route::get('/iclock/test', [iclockController::class, 'test']);
Route::get('/iclock/getrequest', [iclockController::class, 'getrequest']);
Route::post('/iclock/devicecmd', [iclockController::class, 'devicecmd']);
