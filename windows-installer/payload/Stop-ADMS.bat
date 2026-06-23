@echo off
rem Stop the ADMS web server and pause the scheduler. Run as Administrator.
call "%~dp0adms-config.bat"
echo Stopping ADMS...
schtasks /Change /TN "%SCHED_TASK%" /DISABLE >nul 2>&1
net stop "%APACHE_SVC%" 2>nul
echo ADMS stopped.
timeout /t 2 >nul
