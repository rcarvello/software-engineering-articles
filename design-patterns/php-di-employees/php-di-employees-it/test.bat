@echo off
setlocal
chcp 65001 >nul
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
    echo [ERRORE] PHP non trovato nel PATH.
    pause
    exit /b 1
)

php tests\smoke.php
echo.
pause

endlocal
