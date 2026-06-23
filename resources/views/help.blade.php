@extends('layouts.app')

@section('content')
<style>
    .help-toc { position: sticky; top: 1rem; }
    .help-toc a { display:block; padding:.2rem 0; color:#475467; text-decoration:none; font-size:.9rem; }
    .help-toc a:hover { color:#0d6efd; }
    .help-section { scroll-margin-top: 1rem; }
    .help-section h3 { margin-top:.25rem; }
    .help-card { background:#fff; border-radius:.75rem; box-shadow:0 1px 3px rgba(16,24,40,.08); padding:1.5rem; margin-bottom:1.5rem; }
    .flow pre { background:#0d1117; color:#e6edf3; padding:1rem; border-radius:.5rem; overflow:auto; font-size:.8rem; }
    table.help td, table.help th { vertical-align: top; }
    .badge-evt { font-family: monospace; }
</style>

<div class="row">
    <div class="col-lg-3 d-none d-lg-block">
        <nav class="help-toc">
            <h6 class="text-uppercase text-muted small">Contents</h6>
            <a href="#overview">1. What is ADMS</a>
            <a href="#architecture">2. Architecture</a>
            <a href="#dataflow">3. Data flow</a>
            <a href="#concepts">4. Key concepts</a>
            <a href="#screens">5. The screens</a>
            <a href="#setup">6. Setup tutorial</a>
            <a href="#behaviors">7. Expected behaviors</a>
            <a href="#activity">8. Activity log reference</a>
            <a href="#troubleshooting">9. Troubleshooting</a>
            <a href="#commands">10. Commands & config</a>
            <a href="#deployment">11. Deployment</a>
        </nav>
    </div>

    <div class="col-lg-9">
        <h2 class="mb-4">Help &amp; Tutorial</h2>

        {{-- 1 --}}
        <section id="overview" class="help-card help-section">
            <h3>1. What is ADMS</h3>
            <p>ADMS (Attendance Device Management System) is an <strong>edge server</strong> that sits on the local network with your ZKTeco devices. It does two jobs:</p>
            <ul>
                <li><strong>Collects</strong> attendance taps from the devices over the LAN.</li>
                <li><strong>Bridges</strong> them to the <strong>DMPI</strong> payroll app — translating device IDs into payroll employee IDs and pushing each punch.</li>
            </ul>
            <p>It also <strong>auto-enrolls</strong> employees onto the devices (pushing each person's ID, name, and RFID card down to the hardware) so nobody types employees in by hand. It replaces the older <code>tcd-local-server</code>.</p>
        </section>

        {{-- 2 --}}
        <section id="architecture" class="help-card help-section flow">
            <h3>2. Architecture</h3>
            <p>Three parts. The devices talk only to ADMS (LAN); ADMS talks to DMPI (internet).</p>
<pre>
  ZKTeco devices            ADMS (this app)                 DMPI payroll
  (RFID, IN/OUT)            Laravel + MySQL                 (Django + Postgres)
  ──────────────           ───────────────                 ────────────────
        │  punch (LAN)            │                                │
        │ ───────────────────────▶  store + map                    │
        │  /iclock/cdata          │ ──── push punch ───────────────▶  /api/sync-logs/
        │                         │                                │
        │  poll for commands      │ ◀──── pull roster/devices ─────  /api/v2/read_*
        │ ◀───────────────────────  enroll users                   │
        │  /iclock/getrequest     │                                │
</pre>
            <ul>
                <li><strong>Devices → ADMS:</strong> ZKTeco "push protocol" over HTTP on the LAN. Devices send punches and poll for commands.</li>
                <li><strong>ADMS ↔ DMPI:</strong> HTTPS REST API. ADMS pushes punches and pulls the roster/device assignments.</li>
                <li><strong>ADMS itself:</strong> a Laravel app with a MySQL database, a web dashboard, and background scheduled jobs.</li>
            </ul>
        </section>

        {{-- 3 --}}
        <section id="dataflow" class="help-card help-section">
            <h3>3. Data flow</h3>
            <h5>A. A tap becomes a payroll punch (the main flow)</h5>
            <ol>
                <li>Employee taps their RFID card → device sends a punch to ADMS carrying the <strong>device PIN</strong> (e.g. <code>270_39475</code>) + timestamp.</li>
                <li>ADMS stores it in <code>attendances</code> (status <em>pending</em>) and <strong>freezes its IN/OUT</strong> from the device's direction at that moment.</li>
                <li>Every minute, ADMS resolves the PIN → <strong>payroll employee id</strong> (via the employee map) and pushes the punch (with its frozen IN/OUT) to DMPI <code>/api/sync-logs/</code>.</li>
                <li>DMPI records the punch and decides which day it belongs to (pairing/night-shift logic). The punch flips to <em>synced</em>.</li>
            </ol>
            <h5>B. Enrollment (employees onto devices)</h5>
            <ol>
                <li>In DMPI, an employee is assigned to a device (e.g. "SP - BMirk and Allied - PBW IN").</li>
                <li>ADMS pulls those assignments + the roster (name, CHAPA, RFID).</li>
                <li>You link the physical device (by serial) to that DMPI device in ADMS.</li>
                <li>ADMS queues a <code>DATA UPDATE USERINFO</code> command; the device polls, receives it, and creates the user with their card.</li>
            </ol>
            <p class="text-muted small mb-0"><strong>Direction matters:</strong> a punch's <code>in</code>/<code>out</code> is set from the device's direction (or, for a "both" device, from the tap's own state code) <em>at the moment the tap is received</em>, then frozen onto the record. DMPI uses it to pair clock-ins with clock-outs. Changing a device's direction later only affects <em>future</em> taps — it never rewrites past punches, and IN/OUT is read-only on the Attendance screen.</p>
        </section>

        {{-- 4 --}}
        <section id="concepts" class="help-card help-section">
            <h3>4. Key concepts</h3>
            <table class="table help">
                <tbody>
                <tr><th>Device PIN</th><td>What the device knows an employee by, and what every tap carries. Format is <code>{company}_{chapa}</code> (e.g. <code>270_39475</code>). The company prefix is required because CHAPA numbers repeat across the manpower companies — the composite makes each one globally unique.</td></tr>
                <tr><th>CHAPA No.</th><td>The employee's badge number in DMPI.</td></tr>
                <tr><th>Payroll ID</th><td>DMPI's internal <code>Employee.id</code> — the value ADMS actually sends to payroll. The device never knows this; ADMS translates the PIN to it.</td></tr>
                <tr><th>Employee map</th><td>The translation table in ADMS: device PIN → payroll id (+ name, company, RFID). Built from the roster pull.</td></tr>
                <tr><th>Direction</th><td>Each device is <code>IN</code>, <code>OUT</code>, or <code>BOTH</code>. It sets the in/out label <strong>frozen onto each punch as it arrives</strong>. A BOTH device reads in/out from the tap's own state code. Editing a device's direction only changes <em>future</em> punches; existing ones keep what they were stamped with.</td></tr>
                <tr><th>RFID / Card</th><td>The physical card number, assigned to the employee in DMPI. ADMS pushes it to the device exactly as DMPI stores it (no conversion).</td></tr>
                <tr><th>sync_id</th><td>Idempotency key per punch (<code>{serial}-{id}</code>). Prevents the same punch being double-counted in payroll.</td></tr>
                </tbody>
            </table>
        </section>

        {{-- 5 --}}
        <section id="screens" class="help-card help-section">
            <h3>5. The screens</h3>
            <table class="table help">
                <tbody>
                <tr><th>Monitoring</th><td>The single "is everything OK?" page (the old <em>Dashboard</em> and <em>Health</em> are now merged here, and both redirect to it). Shows an overall status banner, <strong>At a glance</strong> stat cards (devices online/offline, punches today, pending/failed sync, unmapped PINs), and a <strong>System health</strong> grid of checks (database, scheduler, payroll credentials, DMPI reachable, sync backlog, roster, devices, recent errors). Each health card is clickable to the underlying data. Auto-refreshes every 30s and has the <strong>Start scheduler</strong> button.</td></tr>
                <tr><th>Devices</th><td>Each device's online status, name/location, IN/OUT direction, and which DMPI device it's linked to. Set direction + link here, and use <strong>Sync enrollments</strong> to push users to a device.</td></tr>
                <tr><th>Employees</th><td>Two tabs: <strong>Mapped</strong> — the roster (name, company, CHAPA, device PIN, <strong>RFID card</strong>, payroll id, and the <strong>physical device serial(s)</strong> each person is enrolled on); searchable across <em>every</em> column and filterable by enrolled device. <strong>Unmapped PINs</strong> — people tapping who aren't matched to an employee yet. The <strong>Sync from DMPI</strong> button lives here.</td></tr>
                <tr><th>Attendance</th><td>Every punch with employee details, device + location, and its full lifecycle as three time columns — <strong>Punched</strong> (at the device), <strong>Received</strong> (by ADMS), <strong>Synced</strong> (to payroll) — plus a sync-status badge. IN/OUT is shown read-only. Filter by date/device/company/employee/sync. <strong>Sync to payroll now</strong> pushes pending punches on demand.</td></tr>
                <tr><th>Logs ▾ → Server Activity</th><td>What ADMS itself is doing (sync runs, pulls, reconciles, errors). See section 8.</td></tr>
                <tr><th>Logs ▾ → Device Check-ins</th><td>When each device connected and reported its settings — useful to confirm a device is reaching the server. (Was "Device Log".) Auto-pruned after 30 days.</td></tr>
                <tr><th>Logs ▾ → Device Messages</th><td>Everything a device sends — attendance taps and on-device activity (menu/settings) — with a plain-language "What happened" column and the raw payload tucked into "Technical details". (Was "Finger Log".) Auto-pruned after 30 days.</td></tr>
                </tbody>
            </table>

            <h6 class="mt-3">Buttons &amp; actions</h6>
            <table class="table help">
                <thead><tr><th>Button</th><th>Where</th><th>What it does</th></tr></thead>
                <tbody>
                <tr>
                    <td><span class="badge bg-success">Sync from DMPI</span></td>
                    <td>Employees</td>
                    <td><strong>Pulls</strong> from DMPI on demand: the employee roster (names + RFID), the device list + assignments, then re-queues enrollment commands. Use it after you change something in DMPI (e.g. add an employee, set an RFID, assign someone to a device) and want it reflected now instead of waiting for the hourly sync. Inbound (DMPI → ADMS).</td>
                </tr>
                <tr>
                    <td><span class="badge bg-success">Sync to payroll now</span></td>
                    <td>Attendance</td>
                    <td><strong>Pushes</strong> all pending punches to DMPI immediately, instead of waiting for the every-minute job. Outbound (ADMS → DMPI). Safe to click anytime; already-synced punches are skipped.</td>
                </tr>
                <tr>
                    <td><span class="badge bg-success">Sync enrollments</span></td>
                    <td>Devices (per device)</td>
                    <td>Queues the <code>DATA UPDATE/DELETE USERINFO</code> commands to make that physical reader's user list match its DMPI assignments. The device applies them on its next check-in. Use after linking a device or changing who's assigned to it.</td>
                </tr>
                <tr>
                    <td><span class="badge bg-outline-primary border text-primary">Start scheduler</span></td>
                    <td>Monitoring</td>
                    <td>(Re)starts the background scheduler if it has stopped — e.g. after a server/container restart. The scheduler is what makes the automatic every-minute/hourly syncs run. If the <em>Scheduler</em> health card is red, click this. No terminal needed; it won't start a duplicate if one's already running.</td>
                </tr>
                </tbody>
            </table>
            <p class="text-muted small mb-0"><strong>Sync from DMPI</strong> = pull data in; <strong>Sync to payroll now</strong> = push punches out; <strong>Sync enrollments</strong> = push users down to a reader. Each also has an equivalent artisan command (section 10), and all run automatically on a schedule.</p>
        </section>

        {{-- 6 --}}
        <section id="setup" class="help-card help-section">
            <h3>6. Setup tutorial</h3>
            <ol>
                <li><strong>Connect ADMS to DMPI.</strong> Set <code>PAYROLL_URL</code>, <code>PAYROLL_USERNAME</code>, <code>PAYROLL_PASSWORD</code> in <code>.env</code> (a timekeeper-access service account). User-Agent must be <code>YP_TIMEKEEPER</code>.</li>
                <li><strong>Pull the data.</strong> Click <strong>Sync from DMPI</strong> on the Employees page (or run <code>php artisan payroll:sync-roster</code> + <code>payroll:sync-devices</code>, or let the scheduler do it). This fills Employees + the device dropdown.</li>
                <li><strong>Point a device at ADMS.</strong> On the device's Cloud/ADMS setting, set the server to this machine's LAN IP. It auto-appears on the Devices page and shows <em>online</em>.</li>
                <li><strong>Configure the device in ADMS.</strong> Set its <strong>Direction</strong> (IN/OUT/BOTH) and pick its matching <strong>Payroll device</strong> from the dropdown. Save.</li>
                <li><strong>Enroll.</strong> Click <strong>Sync enrollments</strong> → ADMS pushes the assigned employees (PIN + name + card) to the device.</li>
                <li><strong>Verify.</strong> Tap a card → the punch appears on Attendance (pending → green <em>synced</em>) → confirm it in DMPI.</li>
                <li><strong>Keep it running.</strong> The scheduler must be running for automatic sync (<code>php artisan schedule:work</code>, or the <strong>Start scheduler</strong> button on Monitoring). If the <em>Scheduler</em> health card ever goes red, click Start scheduler.</li>
            </ol>
        </section>

        {{-- 7 --}}
        <section id="behaviors" class="help-card help-section">
            <h3>7. Expected behaviors</h3>
            <table class="table help">
                <tbody>
                <tr><th>Online status</th><td>A device shows <strong>online</strong> if it contacted ADMS within <strong>5 minutes</strong>; otherwise offline. The Devices page refreshes this every 60s.</td></tr>
                <tr><th>Push cadence</th><td>Pending punches are pushed <strong>every minute</strong>. "0 synced, 0 failed" simply means nothing was waiting.</td></tr>
                <tr><th>Enrollment reconcile</th><td>Runs <strong>every 15 minutes</strong>; queues device commands only when assignments change.</td></tr>
                <tr><th>Roster / device pull</th><td>Runs <strong>hourly</strong>. Pulls the full roster + assignments from DMPI.</td></tr>
                <tr><th>IN/OUT is frozen</th><td>A punch's IN/OUT is locked in <strong>when it's received</strong>, from the device's direction at that moment. It's read-only afterward; changing a device's direction only affects future taps.</td></tr>
                <tr><th>Day attribution</th><td>ADMS sends the raw timestamp; <strong>DMPI decides which day</strong> a punch belongs to (it may re-date a night-shift OUT to the previous day). A synced punch may appear on a different date in DMPI than the tap date — that's expected.</td></tr>
                <tr><th>Duplicates</th><td>Re-sent device records are de-duplicated; DMPI also ignores duplicate <code>sync_id</code>s.</td></tr>
                <tr><th>Offline resilience</th><td>If a device loses network, it buffers punches and sends them when it reconnects. If ADMS loses internet, punches queue locally and push when it returns.</td></tr>
                <tr><th>Log retention</th><td>Raw device logs are pruned after 30 days. Attendance records are kept.</td></tr>
                </tbody>
            </table>
        </section>

        {{-- 8 --}}
        <section id="activity" class="help-card help-section">
            <h3>8. Activity log reference</h3>
            <p>The <strong>Server Activity</strong> page logs each scheduled job. Levels: <span class="badge bg-secondary">info</span> normal, <span class="badge bg-warning text-dark">warning</span> some failures, <span class="badge bg-danger">error</span> the job itself failed.</p>
            <table class="table help">
                <tbody>
                <tr><td><span class="badge bg-light text-dark border badge-evt">attendance.sync</span></td><td>The minute push. <em>"X synced, Y failed"</em>. 0/0 = nothing was pending (healthy idle).</td></tr>
                <tr><td><span class="badge bg-light text-dark border badge-evt">enrollment.reconcile</span></td><td>The 15-min enrollment check. <em>"Commands queued: N"</em> — 0 means devices already match payroll.</td></tr>
                <tr><td><span class="badge bg-light text-dark border badge-evt">devices.sync</span></td><td>The hourly device + assignment pull. <em>"Devices: 89, assignments: 139047"</em> = how much was pulled.</td></tr>
                <tr><td><span class="badge bg-light text-dark border badge-evt">roster.sync</span></td><td>The hourly employee pull. If it shows an <span class="badge bg-danger">error</span> ("timed out"), that's the known DMPI <code>read_employees</code> slowness (see troubleshooting).</td></tr>
                <tr><td><span class="badge bg-light text-dark border badge-evt">dmpi.pull</span></td><td>A manual <strong>Sync from DMPI</strong> button press — pulled roster + devices and reconciled enrollments on demand.</td></tr>
                <tr><td><span class="badge bg-light text-dark border badge-evt">scheduler.start</span></td><td>The <strong>Start scheduler</strong> button was used to (re)start the background scheduler.</td></tr>
                </tbody>
            </table>
        </section>

        {{-- 9 --}}
        <section id="troubleshooting" class="help-card help-section">
            <h3>9. Troubleshooting</h3>
            <table class="table help">
                <thead><tr><th>Symptom</th><th>Likely cause &amp; fix</th></tr></thead>
                <tbody>
                <tr>
                    <td>Punch shows <span class="badge bg-warning text-dark">unmapped</span></td>
                    <td>The tapped PIN isn't in the employee map. Either the roster hasn't loaded, or the device was enrolled with the wrong User ID. The User ID on the device must equal the composite <code>{company}_{chapa}</code>. Check the <strong>Employees → Unmapped device PINs</strong> list.</td>
                </tr>
                <tr>
                    <td>Punch stuck <span class="badge bg-secondary">pending</span></td>
                    <td>Nothing has pushed it. Confirm the scheduler is running (Monitoring → <strong>Scheduler</strong> card should be green; click <strong>Start scheduler</strong> if not) or click <strong>Sync to payroll now</strong> on Attendance. Also check payroll credentials are set in <code>.env</code>.</td>
                </tr>
                <tr>
                    <td><strong>Scheduler</strong> health card is <span class="badge bg-danger">fail</span></td>
                    <td>The background scheduler stopped (common after a server/container restart, since it isn't auto-started). Click <strong>Start scheduler</strong> on the Monitoring page — automatic syncing resumes within a minute. For a permanent fix, run it under supervisor/cron so it restarts on boot.</td>
                </tr>
                <tr>
                    <td>Punch shows <span class="badge bg-danger">failed</span></td>
                    <td>Hover the badge for the reason. <em>"No Employee"</em> = the payroll id doesn't exist / wrong mapping. <em>"No schedule"</em> = the employee has no work schedule in DMPI for that day (a DMPI data fix).</td>
                </tr>
                <tr>
                    <td>Synced, but not in DMPI's Daily Logs</td>
                    <td>DMPI re-dated it (night-shift / schedule). Look at the day before/after, or check the punch's <code>group_date</code> in DMPI. The sync itself is fine if Attendance shows green.</td>
                </tr>
                <tr>
                    <td>Device shows <span class="badge bg-secondary">offline</span></td>
                    <td>No contact in 5+ minutes. Check the device's power/network and that its server setting points to ADMS's LAN IP. Buffered punches flush on reconnect.</td>
                </tr>
                <tr>
                    <td>Employees / roster won't populate</td>
                    <td>The DMPI <code>read_employees</code> endpoint is slow/hangs at scale (it returns the whole cluster). Watch <strong>Server Activity</strong> for a <code>roster.sync</code> error. Fix is on the DMPI side (optimize / scope that endpoint). Until then, the roster can be seeded manually.</td>
                </tr>
                <tr>
                    <td>Enrollment didn't take on the device</td>
                    <td>The device may reject the User ID format (underscore) or card format. Confirm on the device's user list; check <strong>Server Activity</strong> for the reconcile, and the device's command result. Tap the card — if a punch arrives with the right PIN, enrollment worked.</td>
                </tr>
                <tr>
                    <td>How to debug anything</td>
                    <td>1) <strong>Server Activity</strong> page (what ran + errors). 2) The <strong>Attendance</strong> sync badge + error reason. 3) Run a command by hand to see output (<code>php artisan payroll:sync-attendances</code>). 4) Laravel logs: <code>storage/logs/laravel.log</code>.</td>
                </tr>
                </tbody>
            </table>
        </section>

        {{-- 10 --}}
        <section id="commands" class="help-card help-section flow">
            <h3>10. Commands &amp; config</h3>

            <h6>Where &amp; how to run commands</h6>
            <p>All commands are run with <strong>artisan</strong>, from the <strong>project root</strong> (the folder containing the <code>artisan</code> file). They are <em>server-side</em> commands — you run them in a terminal on the ADMS host (or its container), not in the browser.</p>
            <ul>
                <li><strong>Docker / Sail (current dev setup):</strong> prefix with Sail so it runs inside the container —
<pre>cd /path/to/adms-server
./vendor/bin/sail artisan payroll:sync-attendances</pre></li>
                <li><strong>Native server (no Docker):</strong> use PHP directly —
<pre>cd /var/www/adms
php artisan payroll:sync-attendances</pre></li>
                <li><strong>Into a running Docker container:</strong>
<pre>docker compose exec web php artisan payroll:sync-attendances</pre></li>
            </ul>
            <p class="text-muted small">Tip: every UI button maps to a command here — <strong>Sync from DMPI</strong> = <code>sync-roster</code> + <code>sync-devices</code> + <code>reconcile-enrollments</code>; <strong>Sync to payroll now</strong> = <code>sync-attendances</code>; <strong>Sync enrollments</strong> = <code>reconcile-enrollments</code>; <strong>Start scheduler</strong> = <code>schedule:work</code>. Useful for testing or one-off runs; in normal operation the <strong>scheduler</strong> runs them for you (see Deployment).</p>

            <h6>Artisan commands</h6>
            <table class="table help">
                <tbody>
                <tr><td><code>payroll:sync-attendances</code></td><td>Push pending punches to DMPI (auto every minute).</td></tr>
                <tr><td><code>payroll:sync-roster</code></td><td>Pull the employee roster (incl. RFID) into the map (auto hourly).</td></tr>
                <tr><td><code>payroll:sync-devices</code></td><td>Pull the device list + assignments from DMPI (auto hourly).</td></tr>
                <tr><td><code>payroll:reconcile-enrollments</code></td><td>Queue device commands to match users to assignments (auto every 15 min).</td></tr>
                <tr><td><code>logs:prune</code></td><td>Delete raw device logs older than the retention window (auto daily).</td></tr>
                <tr><td><code>schedule:work</code></td><td>Run the scheduler so all the above fire automatically.</td></tr>
                </tbody>
            </table>
            <h6>Key <code>.env</code> settings</h6>
            <table class="table help">
                <tbody>
                <tr><td><code>PAYROLL_URL</code></td><td>DMPI base URL.</td></tr>
                <tr><td><code>PAYROLL_USERNAME</code> / <code>PAYROLL_PASSWORD</code></td><td>The timekeeper service account.</td></tr>
                <tr><td><code>PAYROLL_USER_AGENT</code></td><td><code>YP_TIMEKEEPER</code> (DMPI grants access by user-agent).</td></tr>
                <tr><td><code>PAYROLL_TIMEOUT</code></td><td>Seconds to wait on DMPI's slow read endpoints (default 600).</td></tr>
                <tr><td><code>PAYROLL_BATCH_SIZE</code></td><td>How many punches to push per batch.</td></tr>
                <tr><td><code>ADMS_LOG_RETENTION_DAYS</code></td><td>Days of raw device logs to keep (default 30).</td></tr>
                </tbody>
            </table>
        </section>

        {{-- 11 --}}
        <section id="deployment" class="help-card help-section flow">
            <h3>11. Deployment</h3>
            <p>ADMS runs as <strong>one instance per site</strong>, on a machine on the same LAN as that site's devices. It must be reachable by the devices (port 80) and able to reach DMPI over the internet.</p>

            <h6>Host requirements</h6>
            <ul>
                <li>A small always-on box or VM (a mini-PC / 4 vCPU · 8 GB RAM · SSD is plenty), on the device LAN.</li>
                <li><strong>PHP 8.1+</strong>, <strong>Composer</strong>, <strong>MySQL 8</strong>, and a web server — <em>or</em> just Docker (the repo ships a Docker setup).</li>
                <li>Gigabit NIC reachable by the devices; outbound internet to DMPI. UPS + a daily DB backup recommended.</li>
            </ul>

            <h6>Option A — Docker (recommended; matches the dev setup)</h6>
<pre>git clone &lt;repo&gt; adms-server && cd adms-server
cp .env.example .env            # then edit: APP_URL, DB_*, PAYROLL_*
./vendor/bin/sail up -d --build # starts app + MySQL
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --force</pre>
            <p>Run the <strong>scheduler</strong> (this is what makes sync run automatically). Either add a dedicated long-running process:</p>
<pre>./vendor/bin/sail artisan schedule:work    # keep running (e.g. via a compose service / supervisor)</pre>

            <h6>Option B — Native (PHP-FPM + nginx + MySQL)</h6>
<pre>git clone &lt;repo&gt; /var/www/adms && cd /var/www/adms
composer install --no-dev --optimize-autoloader
cp .env.example .env            # edit APP_URL, DB_*, PAYROLL_*
php artisan key:generate
php artisan migrate --force
php artisan config:cache && php artisan route:cache</pre>
            <p>Point your web server's document root at <code>public/</code> and serve on <strong>port 80</strong> so devices can reach <code>/iclock/*</code>.</p>
            <p>Add the <strong>scheduler to cron</strong> (the standard Laravel one-liner) so all jobs fire on time:</p>
<pre>* * * * * cd /var/www/adms && php artisan schedule:run >> /dev/null 2>&1</pre>

            <h6>Point the devices at ADMS</h6>
            <p>On each device's Cloud/ADMS server setting, set the server to ADMS's <strong>LAN IP</strong> and port <strong>80</strong>. It will auto-register on the Devices page; then set its direction + payroll link and Sync enrollments.</p>

            <h6>Updating</h6>
<pre>git pull
composer install --no-dev --optimize-autoloader   # (sail: ./vendor/bin/sail composer install ...)
php artisan migrate --force
php artisan config:cache route:cache
# restart php-fpm / the container</pre>

            <h6>Important notes</h6>
            <ul>
                <li><strong>The scheduler must be running</strong> (cron or <code>schedule:work</code>) — without it, punches won't sync automatically (only via the manual button/command).</li>
                <li><strong>Set the device clocks to the correct timezone</strong> — DMPI files punches by the device-stamped time.</li>
                <li><strong>Security:</strong> the dashboard currently has no login. Add authentication before exposing it beyond a trusted network. The device endpoints (<code>/iclock/*</code>) are intentionally open + CSRF-exempt so devices can post.</li>
                <li><strong>Remote dashboard access</strong> (optional): expose only the dashboard via a tunnel (e.g. Tailscale) — keep the device LAN traffic local.</li>
            </ul>
        </section>
    </div>
</div>
@endsection
