@echo off
rem Re-cache Laravel config/routes/views after editing app\.env
rem (e.g. after filling in PAYROLL_USERNAME / PAYROLL_PASSWORD).
call "%~dp0adms-config.bat"
cd /d "%APP%"
"%PHP%" artisan config:clear
"%PHP%" artisan view:cache
echo Configuration reloaded.
