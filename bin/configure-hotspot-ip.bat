@echo off
:: Couriva - One-Click iPhone Hotspot IP Configuration (172.20.10.2)
:: Right-click and select "Run as Administrator"

net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [!] ERROR: This script must be run as Administrator.
    echo Right-click configure-hotspot-ip.bat and select "Run as Administrator".
    pause
    exit /b 1
)

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0configure-hotspot-ip.ps1" -IPAddress "172.20.10.2" -PrefixLength 28 -Gateway "172.20.10.1" -DNS "172.20.10.1"

echo.
pause
