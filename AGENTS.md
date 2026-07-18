# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## What this is

An ADMS (Attendance Device Management System) edge server. It sits between ZKTeco
biometric/access devices (via the ZKTeco "push" protocol, a.k.a. iclock) and a
remote DMPI **payroll** system. It ingests punches from devices, pushes them to
payroll, pulls the employee roster + device assignments back from payroll, and
keeps each device's on-device user list in sync.

> The upstream README (saifulcoder/adms-server-ZKTeco) describes only the original
> punch-storage app. This fork adds the entire payroll-sync, enrollment, and
> monitoring layer below — treat the architecture notes here as authoritative.

## Commands

Development runs on **Laravel Sail** (Docker; `compose.yaml`, image `sail-8.5/app`
+ MySQL 8.4). Prefer Sail over a bare `php artisan` (no local PHP is assumed).

```bash
./vendor/bin/sail up -d                     # start app + mysql
./vendor/bin/sail artisan migrate           # run migrations
./vendor/bin/sail test                      # full test suite
./vendor/bin/sail test --filter AttendanceScreenTest        # one test class
./vendor/bin/sail test --filter test_export_honors_the_sync_filter   # one test
./vendor/bin/sail pint                       # format (Laravel Pint)
./vendor/bin/sail artisan schedule:run       # run due scheduled commands once
```

Tests use an in-memory SQLite DB (`phpunit.xml`) with `RefreshDatabase` — they do
not touch the MySQL container. To swap the payroll HTTP client in a test, bind
`App\Contracts\PayrollClient` to `Tests\Support\FakePayrollClient`.

## Architecture

### Data flow (three directions)

1. **Device → ADMS** (`app/Http/Controllers/iclockController.php`): the push-protocol
   endpoints under `/iclock/*` (`routes/web.php`). Devices `handshake` (GET cdata,
   ~every 30s), POST punches (`receiveRecords`), poll for queued commands
   (`getrequest`), and report command results (`devicecmd`). Every contact calls
   `touchOnline()` to drive the Online/Offline status.
2. **ADMS → payroll** (`app/Sync/AttendanceSync.php`): pushes unsynced punches to
   DMPI via the `PayrollClient` contract.
3. **Payroll → ADMS → device** (`RosterSync`, `DeviceInfoSync`, `EnrollmentReconciler`):
   pull the roster + device assignments, then queue push-protocol commands so each
   device's user list matches its payroll assignments.

### Critical invariants (don't break these)

- **IN/OUT is frozen at arrival.** When a punch is received, `LogType::resolve()`
  computes `log_type` from the *device's* current `direction` and the punch's state
  code, and stores it on the row. Never recompute IN/OUT from the device's
  (mutable) direction later — not in the UI, not in the sync. A device with no
  direction yields `log_type = null`, which `AttendanceSync` flags as unsyncable.
- **Employee identity is the device PIN.** `attendances.employee_id` holds the
  device PIN, formatted `"{company}_{chapa}"`. It is resolved to a
  `payroll_employee_id` through the `employee_map` table (`device_pin` column).
  An unmapped PIN leaves the punch unsynced with a recorded `sync_error`.
- **Punch dedup is at the DB.** Ingest uses `insertOrIgnore` against a unique index
  on `(sn, employee_id, timestamp)`, so device re-sends (after a reconnect) are
  dropped silently while still acknowledged to the device.
- **AttendanceSync uses an id cursor, not `where is_sync=false` in a loop.**
  Unsyncable punches stay `is_sync=false` on purpose; advancing past the highest
  id seen attempts each pending row exactly once per run and retries it next run.

### Layers / conventions

- **`app/Queries/`** centralizes "how do we slice this data" rules
  (`AttendanceQuery`, `LogQuery`, `DashboardStats`, `EmployeeDirectory`). The same
  query object backs both the Blade page and its yajra/DataTables server-side AJAX
  endpoint, so filter rules live in one place and are unit-tested independently of
  HTTP. When adding a filterable/exportable screen, add the filter logic here.
- **`app/Sync/`** is the payroll/device integration. `PayrollClient` (in
  `app/Contracts/`) is the seam — bound to `HttpPayrollClient` in
  `AppServiceProvider`, faked in tests. `PunchLog`/`PushResult` are the DTOs
  crossing that seam. `RfidConverter` translates RFID ↔ device card numbers.
- **`app/Console/Commands/`** wraps each `Sync`/`Maintenance` service as an artisan
  command; `app/Console/Kernel.php` schedules them (roster hourly, attendances
  every minute, devices hourly, enrollment reconcile every 15 min, log prune
  daily). All use `withoutOverlapping()`.
- **`app/Health/`** + `app/Maintenance/`: system health checks, scheduler control,
  and the log pruner. Raw `device_log`/`finger_log` are pruned after
  `ADMS_LOG_RETENTION_DAYS` (default 30); attendance rows are never pruned.

### Config

- `config/payroll.php` — DMPI connection (URL, credentials, the required
  `YP_TIMEKEEPER` user-agent, batch size, long read timeout). Driven by `PAYROLL_*`
  env vars.
- `config/adms.php` — `ADMS_LOG_RETENTION_DAYS`.

### Logging tables

- `device_log` — device check-ins. Bare heartbeats are *not* logged (only contacts
  carrying a body/options) to avoid ~2,880 rows/device/day.
- `finger_log` — every raw payload a device POSTs (attendance + on-device activity).
- `error_log` — ingest failures.
