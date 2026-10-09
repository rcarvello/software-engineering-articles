@echo off
setlocal
chcp 65001 >nul
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
    echo.
    echo [ERROR] PHP not found in PATH.
    echo Install PHP 8.1 or later and check that "php -v" works.
    echo.
    pause
    exit /b 1
)

echo.
echo Server running at http://localhost:8000/   ^(press CTRL+C to stop^)
echo.

start "" "http://localhost:8000/"
php -S localhost:8000 -t public

endlocal
