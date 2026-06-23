@echo off
rem Start the ADMS web server and (re)enable the scheduler. Run as Administrator.
call "%~dp0adms-config.bat"
echo Starting ADMS...
net start "%APACHE_SVC%" 2>nul
schtasks /Change /TN "%SCHED_TASK%" /ENABLE >nul 2>&1
echo ADMS is running on http://localhost:%ADMS_PORT%/monitoring
timeout /t 3 >nul
start "" "http://localhost:%ADMS_PORT%/monitoring"
