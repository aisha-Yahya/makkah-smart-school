@echo off
chcp 65001 >nul
cd /d "%~dp0"

set "PHP_BIN=%~dp0php\php.exe"
if not exist "%PHP_BIN%" set "PHP_BIN=C:\tmp\php81\php.exe"

:: Check if port 8000 is listening
netstat -ano | findstr :8000 | findstr LISTENING >nul
if %errorlevel% neq 0 (
    start "Makkah_PHP_Server" /min "%PHP_BIN%" -S 127.0.0.1:8000 -t "%~dp0"
    ping 127.0.0.1 -n 2 >nul
)

:: Open Platform in default browser
start http://127.0.0.1:8000
exit
