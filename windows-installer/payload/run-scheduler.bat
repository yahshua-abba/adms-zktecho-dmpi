@echo off
rem Invoked every minute by the "ADMS-Scheduler" Windows scheduled task.
rem Runs Laravel's scheduler once; the scheduler decides which jobs are due.
call "%~dp0adms-config.bat"
cd /d "%APP%"
"%PHP%" artisan schedule:run >> "%ADMS_ROOT%\logs\scheduler.log" 2>&1
