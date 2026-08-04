# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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
./vendor/bin/sail npm run build              # compile CSS/JS (required — see Frontend)
./vendor/bin/sail npm run dev                # Vite dev server with hot reload
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
- **`sync_excluded` is a manual "skip" mark, not a sync outcome.** Set from the
  Attendance screen's row-selection toolbar. `AttendanceSync::sync()` (the
  automatic/scheduled drain) filters it out; `AttendanceSync::syncIds()` (the
  "sync selected" action) deliberately ignores it, since hand-picking a punch is
  an explicit override of a standing skip. It only ever applies to unsynced rows.

### Layers / conventions

- **`app/Queries/`** centralizes "how do we slice this data" rules
  (`AttendanceQuery`, `LogQuery`, `DashboardStats`, `EmployeeDirectory`). The same
  query object backs both the Blade page and its yajra/DataTables server-side AJAX
  endpoint, so filter rules live in one place and are unit-tested independently of
  HTTP. When adding a filterable/exportable screen, add the filter logic here.
- **Every table is page-size-able, capped at 500** — `App\Support\PerPage::OPTIONS`
  is the single option list both sides read from: DataTables screens (Attendance,
  Devices, Device Check-ins/Messages) pass it into the JS `lengthMenu`; classic
  Laravel-paginated screens (Server Activity, Employees) resolve the `per_page`
  query param through `PerPage::resolve()` (falls back to `PerPage::DEFAULT` for
  anything not in the list — never trust the raw query value) and render the
  picker via `resources/views/partials/per-page-select.blade.php` +
  `partials/pagination-footer.blade.php`. Employees has two independently
  paginated tables on one page (Mapped/Unmapped), so each uses its own
  page/per-page param name and a `tab` param to survive a reload without
  bouncing back to the other tab — see `EmployeeController`.
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

### Dashboard login

Single admin account, no `users` table — credentials are `ADMS_AUTH_USERNAME` /
`ADMS_AUTH_PASSWORD` in `.env`, read via `config('adms.auth')`. `AuthController`
compares them with `hash_equals`; `RequireAdminLogin` (alias `auth.admin` in
`app/Http/Kernel.php`) gates a session flag, not Laravel's `Auth` facade —
there's no Eloquent user to authenticate. In `routes/web.php`, everything
dashboard-facing lives inside the `auth.admin` group. Three route families
stay **outside** it on purpose and must never be added to that group:
`/iclock/*` (device push protocol — devices can't log in) and `/healthz`
(machine-readable status for external uptime monitors). Login attempts are
rate-limited (`throttle:5,1`).

### Frontend

Server-rendered Blade. Bootstrap 5.3 + jQuery + DataTables 1.13, **all bundled by
Vite** — nothing is fetched from a CDN at runtime, including the DM Sans webfont
and the icon font, because this box often sits on a device LAN with no internet.

- `npm run build` is **mandatory**, not cosmetic: `@vite` throws "Vite manifest
  not found" when `public/build/` is absent, and that directory is gitignored.
  `scripts/install.sh` and `scripts/update.sh` both handle it.
- SCSS import order in `resources/sass/app.scss` matters: `_variables` (Bootstrap
  overrides, must precede Bootstrap) → Bootstrap → `_layout` → `_components`.
  Third-party CSS that ships font/image assets is imported from
  `resources/js/app.js` instead, so Vite rewrites those asset URLs; `app.scss` is
  listed last in `@vite([...])` so our rules win over the DataTables theme.
- The shell in `layouts/app.blade.php` is a sidebar + sticky topbar built on
  Bootstrap's *responsive* offcanvas (`.offcanvas-lg`) — a drawer below `lg`, a
  static column above it, with no custom toggle JS. The sidebar's background sits
  on an inner `.app-sidebar-inner` because Bootstrap forces
  `background-color: transparent !important` on `.offcanvas-lg` at `lg` and up.
- **Inline view scripts that use `$` must wait for `DOMContentLoaded`.** The Vite
  bundle is a deferred ES module, so it executes after inline scripts are parsed
  (but before `DOMContentLoaded` fires). See `devices/attendance.blade.php`.
- **Never write a literal `@vite` inside a Blade comment.** Blade compiles it as a
  directive wherever it appears, including inside `<script>` and `//` comments,
  and the page 500s.

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
