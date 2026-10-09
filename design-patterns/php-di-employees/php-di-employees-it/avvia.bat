@echo off
setlocal
chcp 65001 >nul
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
    echo.
    echo [ERRORE] PHP non trovato nel PATH.
    echo Installa PHP 8.1 o superiore e verifica che "php -v" funzioni.
    echo.
    pause
    exit /b 1
)

echo.
echo Server su http://localhost:8000/   ^(CTRL+C per fermare^)
echo.

start "" "http://localhost:8000/"
php -S localhost:8000 -t public

endlocal
