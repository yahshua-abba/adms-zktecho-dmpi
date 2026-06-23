# ADMS Server — Windows installer

Packages this Laravel ADMS server as a single `ADMS-Server-Setup.exe` for
Windows. Self-contained (bundled Apache + PHP), uses **SQLite** (no MySQL),
installs Apache as an auto-start service, and runs the Laravel scheduler every
minute via a Windows scheduled task so the DMPI payroll sync keeps working.

- Listening port: **8080** (change in `payload/adms-config.bat`)
- Admin UI after install: `http://localhost:8080/monitoring`
- Device push endpoint: `http://<pc-ip>:8080/iclock/cdata`

**To build it, see [BUILD.md](BUILD.md).** Short version:

1. `./prepare-payload.sh` (macOS/Linux) → builds `build/app`
2. Drop portable Apache + PHP into `build/runtime` (on Windows)
3. Compile `adms-installer.iss` with Inno Setup → `Output/ADMS-Server-Setup.exe`
