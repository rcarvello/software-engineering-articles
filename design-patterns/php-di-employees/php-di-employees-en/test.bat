@echo off
setlocal
chcp 65001 >nul
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
    echo [ERROR] PHP not found in PATH.
    pause
    exit /b 1
)

php tests\smoke.php
echo.
pause

endlocal
