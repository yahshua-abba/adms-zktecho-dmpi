@echo off
rem Remove the ADMS Windows service, scheduled task and firewall rule.
rem Called by the uninstaller; can also be run manually as Administrator.
call "%~dp0adms-config.bat"
echo Removing ADMS services...
schtasks /Delete /TN "%SCHED_TASK%" /F >nul 2>&1
net stop "%APACHE_SVC%" >nul 2>&1
"%HTTPD%" -n "%APACHE_SVC%" -k uninstall >nul 2>&1
netsh advfirewall firewall delete rule name="ADMS Server (%ADMS_PORT%)" >nul 2>&1
echo Done.
