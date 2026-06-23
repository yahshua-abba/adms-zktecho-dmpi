@echo off
rem ============================================================================
rem Shared paths/config for all ADMS control scripts. Edit ADMS_PORT here if you
rem need to change the listening port (then re-run first-run-setup.bat as admin).
rem ============================================================================

set "ADMS_PORT=8080"

rem ADMS_ROOT = folder this script lives in (the install root), no trailing slash
set "ADMS_ROOT=%~dp0"
if "%ADMS_ROOT:~-1%"=="\" set "ADMS_ROOT=%ADMS_ROOT:~0,-1%"

set "PHP=%ADMS_ROOT%\runtime\php\php.exe"
set "HTTPD=%ADMS_ROOT%\runtime\apache\bin\httpd.exe"
set "MAINCONF=%ADMS_ROOT%\runtime\apache\conf\httpd.conf"
set "APP=%ADMS_ROOT%\app"
set "APACHE_SVC=Apache-ADMS"
set "SCHED_TASK=ADMS-Scheduler"
