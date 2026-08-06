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
- **A contested PIN is refused, never guessed.** `"{company}_{chapa}"` was assumed
  globally unique; in DMPI's live data it is not (observed: `271_14257` and
  `271_14598`, two payroll employees each). `RosterSync` used to write both and let
  the last one win, so a punch was filed against whichever claimant came last in
  the download. A punch carries only the PIN, so nothing on the edge can tell the
  claimants apart — the fix is to stop guessing, not guess better. Contested PINs
  go to `pin_collisions` and are deliberately **left out of `employee_map`**, which
  makes them unresolvable at push time and therefore unsynced-with-a-reason (the
  existing unmapped-PIN path) rather than mis-attributed. The absence of the row is
  the safety: no code path can use an id that isn't there. `EnrollmentReconciler`
  skips them for free for the same reason. An operator decides the owner under
  Employees > PIN conflicts; the decision persists across roster pulls and is
  re-validated against the current claimants each run.
- **`RosterSync` refuses an empty roster** (`EmptyRosterException`), for the same
  reason `DeviceInfoSync` refuses an empty device payload: the run now *removes*
  state on the strength of the payload (clearing collisions that no longer
  collide, dropping mappings that became contested), so acting on a failed call
  would undo real decisions.
- **Sync writes are chunked, never row-at-a-time.** All three stages bulk
  upsert/insert in chunks of 500. The roster is ~9k people and `updateOrCreate`
  cost two queries each, which made *saving* the roster slower than downloading it
  (~45s vs ~18s). `DeviceInfoSync` also dedupes `(device_code, employee_id)` pairs
  before inserting — the payload can repeat a pair, and the unique index rejects
  what the old per-row upsert quietly absorbed.
- **Nothing that talks to DMPI runs inside a web request.** The "Sync from DMPI"
  button hands off to `DmpiSyncLauncher`, which detaches `payroll:sync-all` (the
  same pattern `SchedulerControl` uses for `schedule:work` — this box has no queue
  worker, and the scheduler itself is operator-started). `payroll:sync-all` holds
  a cache lock for the run, so a second press reports "already running" instead of
  doubling load on both sides. This matters because `artisan serve` fronts PHP's
  built-in server: without `PHP_CLI_SERVER_WORKERS` (set in `compose.yaml`) it
  serves **one request at a time**, so a single 10-minute payroll read took the
  whole dashboard — `/login` and `/healthz` included — offline until it finished.
- **A payroll call never fails quietly.** `HttpPayrollClient::login()` throws
  `PayrollAuthException` (carrying DMPI's own wording, e.g. "You've reached the
  maximum login attempt") instead of returning an empty token. Returning `''`
  produced well-formed-but-unauthorized requests whose error bodies parsed as an
  empty roster/device list, so a lockout was indistinguishable from "DMPI has no
  data" — and every health indicator stayed green through it.
- **An empty device-info payload is refused, not applied.** `DeviceInfoSync`
  replaces the whole `device_assignments` table each run, so a zero-device *and*
  zero-assignment response would wipe it and leave `EnrollmentReconciler` queueing
  a delete for every enrolled user on every linked device. That shape is a failed
  call, never a real state, so it raises `EmptyDeviceInfoException`. An empty
  assignment list *alongside* real devices is legitimate and still replaces.
- **"On the clock" means sent, never confirmed.** The Devices page's People
  column and the per-device breakdown behind it (`App\Queries\DeviceRoster`,
  `devices/{device}/people`) show *two* numbers side by side and must keep doing
  so: `device_assignments` is what DMPI says belongs on the clock,
  `device_enrollment` is what this server has told the clock — and
  `EnrollmentReconciler` writes the latter at the moment it *queues* the command,
  not when the device takes it. Collapsing them into one "user count" would
  report a reader that has been unplugged for a fortnight as fully enrolled,
  which is the exact case someone opens this screen to find. The per-person
  "still queued" flag is therefore read out of `device_commands` bodies rather
  than inferred from the two lists agreeing. An assigned employee with no
  `employee_map` row can't be enrolled at all and is surfaced with its cause —
  missing from the roster, or a contested PIN deliberately left unmapped.
- **The command queue is a mailbox, not a setting** (`App\Sync\CommandQueue`,
  `devices/{device}/queue`). Re-pointing a reader at the wrong
  `payroll_device_code` makes the reconciler queue a delete for every user on it,
  and correcting the link afterwards does *not* take those back — the device
  collects and obeys whatever is in `device_commands`. Observed live: a reader
  pointed at an empty test device queued 1,249 deletions, and clearing the link
  left all 1,249 in place. Two rules govern calling them back, and both are
  load-bearing:
  - **Only `pending` is cancellable, and the status is re-asserted in the
    `delete` itself.** A `sent` row is already in the device's hands; deleting it
    would destroy the record of what went out while changing nothing on the
    machine, making the screen understate the damage rather than undo it. The UI
    offers no tick-box on those rows — but `getrequest` flips rows to `sent`
    whenever a device polls, so selecting the pending rows and then deleting them
    *by id* reintroduces the bug in the gap between the two queries. The rows
    that raced away are read back and reported as skipped rather than silently
    dropped, and they are excluded from the enrollment repair below.
  - **Cancelling an add must also delete its `device_enrollment` row.** The
    reconciler writes that row when it *queues* the add (see the invariant
    above), so a cancelled add leaves a row claiming the person was sent; the
    next reconcile finds nothing owed and the person is silently absent from that
    reader for good. Cancelled *removals* need no repair — the reconciler already
    dropped the enrollment row, which is exactly the "still on the machine, no
    longer managed here" state that cancelling one is asking for.
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

- **Three screens answer three *different* "who is on this clock" questions, and
  merging any two of them loses the thing that makes it useful.**
  `DeviceRoster` (`devices/{device}/people`) is per *physical reader*: payroll's
  assignments against what this server has sent it. `TimekeeperDirectory`
  (`devices/timekeepers`) is per *DMPI device code*, and is the only one
  answerable **before** a reader is linked — without it a live reader could be
  pointed at an empty test entry and quietly emptied, because the only way to see
  what was behind that entry was to commit to it. It deliberately reports no
  enrollment state: a payroll device may have two readers linked or none, so
  there is no single "enrolled" answer to give. `DeviceQueue`
  (`devices/{device}/queue`) is what the reader has *not yet been told*.
- **`app/Queries/`** centralizes "how do we slice this data" rules
  (`AttendanceQuery`, `LogQuery`, `DashboardStats`, `EmployeeDirectory`,
  `DeviceRoster`, `TimekeeperDirectory`, `DeviceQueue`). The same
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
- **`app/Health/`** + `app/Maintenance/`: system health checks, scheduler control
  and watchdog, the scheduled-run recorder, and the log pruner. Raw
  `device_log`/`finger_log` are pruned after `ADMS_LOG_RETENTION_DAYS` (default
  30); attendance rows are never pruned.

### Scheduler monitoring

- **Job runs are recorded from the outside.** `ScheduledTaskRecorder` (an event
  subscriber on Laravel's `ScheduledTask*` events) writes one
  `scheduled_task_runs` row per run, surfaced on Logs > Scheduler. Watching from
  outside is the point: each command already logs its own result, but that only
  captures failures a command survives long enough to describe. A job that hangs,
  and a job blocked because the previous one is hanging, both leave the command's
  own logging untouched. `Kernel::job()` also redirects each job's output to
  `storage/logs/tasks/`, since a scheduled command runs in a subprocess and
  otherwise prints to the null device.
- **An overlap-blocked run is not a success.** `withoutOverlapping()` is
  implemented as a `skip` filter, so a blocked job arrives as `ScheduledTaskSkipped`
  — indistinguishable from "a condition said don't run" unless the mutex is
  re-checked, which is what `blockedByItsOwnPreviousRun()` does. It also has a
  second path: if the mutex is taken between the filter check and the launch,
  `Event::run()` returns before setting an exit code and Laravel reports an
  ordinary *finish*, so a null `exitCode` there means the same thing. Both land as
  status `overlapping`. Filed as successes, a job wedged for hours would show an
  unbroken column of green.
- **"Are jobs running?" outranks "is the process alive?".** The Scheduler health
  card and `SchedulerGuard` both consult `ScheduledTaskRun::heartbeatIsFresh()`
  first and only fall back to `SchedulerControl::processState()` to explain a
  silence. The deployment notes offer plain cron calling `schedule:run` as an
  alternative to a long-running `schedule:work`, and that setup has no process for
  `pgrep` to find — judging by the process alone would call a healthy box dead and
  have the watchdog start a scheduler beside cron's, running every job twice.
- **`processState()` has three values, not two.** `UNKNOWN` (no `exec`, no
  `pgrep`) is kept apart from `STOPPED` because they want opposite handling:
  `STOPPED` is a red light and an invitation to restart, `UNKNOWN` means our own
  instrument is broken and must never be reported as an outage or acted on.
- **The watchdog runs from things that are alive by definition.** A watchdog *on
  the schedule* is dead exactly when needed, so `SchedulerGuard::ensureRunning()`
  is called from the Monitoring page, from `/healthz` (which uptime monitors poll
  around the clock), and from `scheduler:guard` for system cron — never from
  `Kernel::schedule()`. It holds an unreleased cache lock as its throttle, because
  a newly launched scheduler is not visible to `pgrep` for a moment and every
  request arriving in that window would otherwise start another one.
- **Payroll-pull freshness comes from `sync_runs`, successes only.** The roster and
  assignment health cards report how long ago each data set last downloaded
  *successfully* — counting rows only ever proved a download happened once, and
  counting attempts would let a job failing hourly report the freshest possible
  data. `payroll:sync-devices` with no `--only` records part `device-info`, not
  `all`, so a device pull cannot vouch for a roster it never touched.

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

### Running things in the container

**Never run artisan as root inside the container.** `sail artisan …` runs as the
app user (`-u sail`); a bare `sail exec …` / `docker compose exec …` runs as
**root**. Anything artisan does as root leaves root-owned files in
`storage/framework/cache`, which the app user then cannot open — and because
every scheduled job holds a cache lock for `withoutOverlapping()`, that single
permission error kills all five *before they start*. Punches stop reaching
payroll while the Scheduler page reports "Running, but no job has ever run" — an
accurate message with no way to say why. Seen in the field, traced to
`install.sh`/`update.sh` starting `schedule:work` without `-u sail`; both now
pass it, and `update.sh` also repairs existing ownership. The manual repair is
`sail exec -u root laravel.test chown -R sail storage bootstrap/cache`.

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
  static column above it; Bootstrap's data-api drives the drawer, so no toggle JS
  of ours. The sidebar's background sits on an inner `.app-sidebar-inner` because
  Bootstrap forces `background-color: transparent !important` on `.offcanvas-lg`
  at `lg` and up.
- **The desktop collapse is a `.sidebar-collapsed` class on `<html>`, restored by
  an inline script in `<head>`, not from the bundle.** Persisted in localStorage
  (`adms.sidebarCollapsed`); the bundle only handles the click. Setting the class
  from the deferred bundle would paint the full-width sidebar first and snap it to
  the rail a frame later. The rail is scoped to `lg` and up on purpose — below it
  the sidebar is a drawer that's open or absent, and a rail would be a second,
  contradictory state. Nav labels are `display: none` in the rail (not
  `visibility`), or they'd keep their width and overflow it. Toggling also
  dispatches a `resize` event: DataTables sizes columns from the container width
  and a CSS width change doesn't fire one on its own.
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
- `config/adms.php` — `ADMS_LOG_RETENTION_DAYS`, `ADMS_ERROR_RETENTION_DAYS`, and
  `adms.scheduler.*` (`ADMS_SCHEDULER_AUTOSTART`, on by default;
  `ADMS_SCHEDULER_AUTOSTART_THROTTLE`).

### Logging tables

- `device_log` — device check-ins. Bare heartbeats are *not* logged (only contacts
  carrying a body/options) to avoid ~2,880 rows/device/day.
- `finger_log` — every raw payload a device POSTs (attendance + on-device activity).
- `error_log` — ingest failures.
- `scheduled_task_runs` — one row per scheduled job run. The fastest-growing table
  here (the punch push alone runs 1,440 times a day) and pruned on the same window,
  except rows still marked `running` — an unfinished row is evidence of a job that
  hung, which is exactly what someone reading this log is looking for.

**Retention has two windows, and the carve-outs are the point.** `LogPruner` ages
routine rows out after `ADMS_LOG_RETENTION_DAYS` (30) and keeps warnings, errors
and rejected payloads for `ADMS_ERROR_RETENTION_DAYS` (365, floored at the routine
window). The split exists because volume and value point in opposite directions in
`activity_log`: nearly every row is the every-minute push reporting nothing
happened, while the few warnings answer "since when has this been failing?" — a
question about months. Three things are never pruned by age alone: attendance rows
(the data of record), `sync_runs`/`scheduled_task_runs` still marked `running` (an
unfinished row *is* the evidence of a hang), and `device_commands` still `pending`
or `sent` — the devices that queue waits on are exactly the ones offline for weeks,
so pruning by age would silently cancel enrollment changes for the readers that had
been unplugged longest.
