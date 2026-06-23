# Building the ADMS Windows installer

This produces a single `ADMS-Server-Setup.exe` that installs the Laravel ADMS
server on Windows with **no separate PHP/MySQL/Apache install** — everything is
bundled. The app uses **SQLite**, so there is no database server to manage.

```
windows-installer/
├─ adms-installer.iss      Inno Setup script -> Setup.exe
├─ prepare-payload.sh      builds build/app from the repo (run on mac/linux)
├─ payload/               control scripts + Apache vhost (committed)
│  ├─ adms-config.bat        shared paths + ADMS_PORT (edit port here)
│  ├─ first-run-setup.bat    one-time configure (db, web server, scheduler)
│  ├─ run-scheduler.bat      called every minute by the scheduled task
│  ├─ Start-ADMS.bat / Stop-ADMS.bat
│  ├─ apply-config.bat       re-cache after editing .env
│  ├─ uninstall-services.bat
│  ├─ .env.production        SQLite production template
│  └─ config/httpd-adms.conf Apache vhost + PHP wiring template
└─ build/                 NOT committed - assembled at build time
   ├─ app/                  Laravel payload  (from prepare-payload.sh)
   └─ runtime/              portable Apache + PHP  (assembled on Windows)
```

The final install lands at `C:\ADMS\` and runs as:
- a Windows **service** `Apache-ADMS` (auto-starts on boot), and
- a **scheduled task** `ADMS-Scheduler` running `php artisan schedule:run` every
  minute (this drives the DMPI payroll sync / roster / enrollment jobs).

---

## Step 1 — Build the app payload (macOS/Linux, this repo)

Requires `php`, `composer`, `npm`.

```bash
./windows-installer/prepare-payload.sh
```

Result: `windows-installer/build/app/` — the app with production composer deps,
built Vite assets, writable `storage/` skeleton, and **no** `.env`, `.git`,
`node_modules`, tests, or secrets. (The composer `vendor/` is pure PHP, so a
mac-built payload runs fine on Windows.)

## Step 2 — Assemble the portable runtime (on Windows)

Goal: `windows-installer\build\runtime\` containing `apache\` and `php\`, both
**relocatable** (they must work from `C:\ADMS\runtime`, not a fixed path).

1. **PHP** — download the **Thread Safe (TS), x64** build of PHP 8.2 or 8.3 for
   Windows from <https://windows.php.net/download/>. Extract to
   `build\runtime\php\`. Confirm `php\php8apache2_4.dll` exists (that exact name
   is referenced by the vhost).
2. Create `build\runtime\php\php.ini` from `php.ini-production` and enable the
   extensions ADMS needs (uncomment by removing the leading `;`):
   ```ini
   extension_dir = "ext"
   extension=pdo_sqlite
   extension=sqlite3
   extension=mbstring
   extension=openssl
   extension=fileinfo
   extension=curl
   extension=zip
   extension=gd
   ```
3. **Apache** — download Apache 2.4 (Win64, VS16/VS17) from
   <https://www.apachelounge.com/download/>. The VC++ runtime version must match
   PHP's. Extract the inner `Apache24\` into `build\runtime\apache\` (so
   `apache\bin\httpd.exe` exists). Also install the matching
   **VC++ Redistributable** on the target — or document it as a prerequisite.

> Why not XAMPP? XAMPP's configs hard-code `/xampp/...` paths and only work when
> installed at `C:\xampp`. The Apache Lounge + PHP combo is relocatable;
> `first-run-setup.bat` rewrites `SRVROOT` to the real install path at install
> time. If you must use XAMPP portable, copy its `apache\` and `php\` into
> `build\runtime\` the same way — the setup script's SRVROOT/PHP rewrites cover
> the apache side, but you'll also need to neutralise XAMPP's
> `httpd-xampp.conf` PHP include so PHP isn't loaded twice.

## Step 3 — (optional) bake in your own .env

`payload\.env.production` ships with **blank** `PAYROLL_*` credentials on
purpose. For your own Del Monte deployment you can either:
- leave them blank and fill them in `C:\ADMS\app\.env` after install (then run
  `apply-config.bat`), **or**
- before building, edit `payload\.env.production` and fill in
  `PAYROLL_USERNAME` / `PAYROLL_PASSWORD`. ⚠️ Anyone who gets the installer then
  has those credentials — only do this for an internal build you control.

## Step 4 — Compile the installer (on Windows)

Install **Inno Setup 6** (<https://jrsoftware.org/isdl.php>), then:

```bat
"C:\Program Files (x86)\Inno Setup 6\ISCC.exe" windows-installer\adms-installer.iss
```

Output: `windows-installer\Output\ADMS-Server-Setup.exe`.

---

## What the end user does

1. Run `ADMS-Server-Setup.exe` (accepts the UAC admin prompt).
2. The installer copies files to `C:\ADMS`, then `first-run-setup.bat`:
   creates `.env`, generates the app key, makes the SQLite DB and migrates it,
   configures + installs the Apache service, opens the firewall port, and
   registers the scheduler task.
3. Done. Admin UI: `http://localhost:8080/monitoring`.
   Point each attendance device's server/cloud setting at
   `http://<this-pc-LAN-IP>:8080` (path `/iclock/cdata` is fixed in firmware).

### Changing the port
Edit `ADMS_PORT` in `C:\ADMS\adms-config.bat`, then re-run
`first-run-setup.bat` as administrator.

### After editing `.env` (e.g. payroll credentials)
Run `C:\ADMS\apply-config.bat` (or just `Stop-ADMS.bat` then `Start-ADMS.bat`).

### Uninstall
Use *Apps & features* (or the Start-menu uninstaller). It removes the service,
scheduled task, firewall rule, the SQLite database, and all files.
