@echo off
rem ============================================================================
rem ADMS first-run setup. Run ONCE, as Administrator (the installer does this
rem automatically). Safe to re-run: every step is idempotent.
rem
rem   - prepares .env (SQLite) + app key + database
rem   - configures the bundled Apache to serve app\public on ADMS_PORT
rem   - installs Apache as an auto-start Windows service
rem   - registers the every-minute Laravel scheduler task
rem   - opens the firewall port
rem ============================================================================
setlocal EnableExtensions
call "%~dp0adms-config.bat"

echo(
echo === ADMS first-run setup  (port %ADMS_PORT%) ===
echo Install root: %ADMS_ROOT%
echo(

if not exist "%ADMS_ROOT%\logs" mkdir "%ADMS_ROOT%\logs"
if not exist "%ADMS_ROOT%\data" mkdir "%ADMS_ROOT%\data"

rem --- 1. .env -----------------------------------------------------------------
if not exist "%APP%\.env" (
    echo [1/9] Creating .env from .env.production
    copy /Y "%APP%\.env.production" "%APP%\.env" >nul
) else (
    echo [1/9] .env already exists - keeping it
)

rem --- 2. point .env at the real sqlite path + url ----------------------------
set "SQLITE=%ADMS_ROOT%\data\database.sqlite"
set "SQLITE_FWD=%SQLITE:\=/%"
echo [2/9] Setting DB path and APP_URL
powershell -NoProfile -Command "(Get-Content -Raw '%APP%\.env') -replace '(?m)^DB_DATABASE=.*', 'DB_DATABASE=%SQLITE_FWD%' -replace '(?m)^APP_URL=.*', 'APP_URL=http://localhost:%ADMS_PORT%' | Set-Content -NoNewline '%APP%\.env'"

rem --- 3. sqlite file ----------------------------------------------------------
if not exist "%SQLITE%" type nul > "%SQLITE%"

rem --- 4. app key + database ---------------------------------------------------
cd /d "%APP%"
echo [3/9] Generating application key
"%PHP%" artisan key:generate --force
echo [4/9] Running migrations + seeders
"%PHP%" artisan migrate --force --seed
echo [5/9] Compiling views
"%PHP%" artisan view:cache
rem NOTE: routes are NOT cached (web.php uses closure routes, which Laravel
rem cannot serialize). Config is NOT cached either, so edits to .env (e.g.
rem PAYROLL_*) take effect immediately.

rem --- 5. apache vhost ---------------------------------------------------------
echo [6/9] Writing Apache vhost
set "ROOT_FWD=%ADMS_ROOT:\=/%"
powershell -NoProfile -Command "(Get-Content -Raw '%ADMS_ROOT%\config\httpd-adms.conf') -replace '__ADMS_PORT__','%ADMS_PORT%' -replace '__ADMS_ROOT__','%ROOT_FWD%' | Set-Content '%ADMS_ROOT%\config\httpd-adms.active.conf'"

echo [7/9] Wiring vhost into Apache (SRVROOT, mod_rewrite, port 80)
rem Make the relocatable Apache point its ServerRoot at our runtime\apache.
powershell -NoProfile -Command "(Get-Content '%MAINCONF%') -replace '(?m)^[ \t]*Define[ \t]+SRVROOT[ \t]+\".*\"','Define SRVROOT \"%ROOT_FWD%/runtime/apache\"' -replace '(?m)^[ \t]*ServerRoot[ \t]+\".*\"','ServerRoot \"%ROOT_FWD%/runtime/apache\"' | Set-Content '%MAINCONF%'"
rem Enable mod_rewrite (Laravel pretty URLs) - uncomment if present.
powershell -NoProfile -Command "(Get-Content '%MAINCONF%') -replace '(?m)^[ \t]*#[ \t]*(LoadModule[ \t]+rewrite_module.*)$','$1' | Set-Content '%MAINCONF%'"
rem Free the default ports so they cannot clash on startup.
powershell -NoProfile -Command "(Get-Content '%MAINCONF%') -replace '(?m)^[ \t]*Listen[ \t]+80[ \t]*$','#Listen 80 (disabled by ADMS)' -replace '(?m)^[ \t]*Listen[ \t]+443[ \t]*$','#Listen 443 (disabled by ADMS)' | Set-Content '%MAINCONF%'"
rem Include our self-contained vhost (idempotent).
findstr /C:"httpd-adms.active.conf" "%MAINCONF%" >nul || >>"%MAINCONF%" echo Include "%ROOT_FWD%/config/httpd-adms.active.conf"

rem --- 6. firewall -------------------------------------------------------------
echo [8/9] Opening firewall port %ADMS_PORT%
netsh advfirewall firewall show rule name="ADMS Server (%ADMS_PORT%)" >nul 2>&1 || netsh advfirewall firewall add rule name="ADMS Server (%ADMS_PORT%)" dir=in action=allow protocol=TCP localport=%ADMS_PORT% >nul

rem --- 7. apache service -------------------------------------------------------
echo [9/9] Installing services
"%HTTPD%" -n "%APACHE_SVC%" -k install -f "%MAINCONF%" >nul 2>&1
sc config "%APACHE_SVC%" start= auto >nul
net stop "%APACHE_SVC%" >nul 2>&1
net start "%APACHE_SVC%"

rem --- 8. scheduler task -------------------------------------------------------
schtasks /Query /TN "%SCHED_TASK%" >nul 2>&1 && schtasks /Delete /TN "%SCHED_TASK%" /F >nul
schtasks /Create /TN "%SCHED_TASK%" /TR "\"%ADMS_ROOT%\run-scheduler.bat\"" /SC MINUTE /MO 1 /RU SYSTEM /RL HIGHEST /F >nul

echo(
echo ============================================================
echo  ADMS is installed and running.
echo  Admin UI:   http://localhost:%ADMS_PORT%/monitoring
echo  Devices push to:  http://THIS-PC-IP:%ADMS_PORT%/iclock/cdata
echo ============================================================
echo(
endlocal
