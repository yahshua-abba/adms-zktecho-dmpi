<?php

namespace App\Http\Controllers;

use App\Maintenance\DatabaseBackup;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\EmployeeMap;
use App\Models\PayrollDevice;
use App\Models\PinCollision;
use App\Models\SyncRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    public function index()
    {
        return view('settings.index', [
            'counts' => $this->whatWouldBeLost(),
            'running' => SyncRun::liveOrClosed(),
        ]);
    }

    /**
     * Drop every table and rebuild them empty.
     *
     * Guarded three ways, because this is the one action in the app that destroys
     * data nothing else can bring back:
     *
     *  - the admin password must be re-entered (being logged in is not enough;
     *    an unattended desk should not be a wipe),
     *  - the word "confirm" must be typed, so it cannot be a stray click,
     *  - a full dump is taken first and the wipe is abandoned if it fails.
     *
     * It also refuses while a download is in flight: that process would carry on
     * writing into tables being dropped underneath it.
     */
    public function clearDatabase(Request $request)
    {
        $request->validate([
            'password' => ['required', 'string'],
            'confirmation' => ['required', 'string'],
        ]);

        // Constant-time, matching AuthController — this is a password check on a
        // destructive action, so it gets the same treatment as the login itself.
        if (! hash_equals((string) config('adms.auth.password'), (string) $request->input('password'))) {
            return back()->withErrors(['password' => 'That password is not correct. Nothing was changed.']);
        }

        if (strtolower(trim((string) $request->input('confirmation'))) !== 'confirm') {
            return back()->withErrors(['confirmation' => 'Type the word confirm exactly. Nothing was changed.']);
        }

        if (SyncRun::liveOrClosed() !== null) {
            return back()->withErrors([
                'confirmation' => 'A download from DMPI is still running. Stop it first — clearing now would pull the tables out from under it.',
            ]);
        }

        $lost = $this->whatWouldBeLost();

        try {
            $backup = DatabaseBackup::create('before-clear');
        } catch (\Throwable $e) {
            return back()->withErrors([
                'password' => 'The safety backup failed, so nothing was cleared: '.$e->getMessage(),
            ]);
        }

        Artisan::call('migrate:fresh', ['--force' => true]);

        // activity_log is dropped along with everything else, so this entry is
        // written after the rebuild — it records that the wipe happened, not a
        // surviving trace of it. The file log survives independently either way.
        $where = $backup ? basename($backup) : 'no dump (non-MySQL connection)';
        $summary = sprintf(
            'Database cleared from Settings. Removed %d punches (%d never synced), %d employees, %d devices. Backup: %s.',
            $lost['attendances'], $lost['unsynced'], $lost['employees'], $lost['devices'], $where
        );

        ActivityLog::record('database.cleared', $summary, 'error');
        Log::warning('[ADMS] '.$summary);

        return redirect()->route('settings')->with('success', $summary);
    }

    /** @return array<string, int> */
    private function whatWouldBeLost(): array
    {
        return [
            'attendances' => Attendance::count(),
            // Called out on its own: a synced punch exists in payroll, an unsynced
            // one exists only here and is gone for good.
            'unsynced' => Attendance::where('is_sync', false)->count(),
            'employees' => EmployeeMap::count(),
            'conflicts' => PinCollision::count(),
            'devices' => Device::count(),
            'devicesWithDirection' => Device::whereNotNull('direction')->count(),
            'payrollDevices' => PayrollDevice::count(),
            'assignments' => DeviceAssignment::count(),
        ];
    }
}
