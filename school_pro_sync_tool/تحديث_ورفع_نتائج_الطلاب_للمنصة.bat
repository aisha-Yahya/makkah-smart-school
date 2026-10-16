@echo off
title School Pro Sync Tool - Makkah Smart School
cd /d "%~dp0"

where python >nul 2>nul
if %errorlevel% equ 0 (
    start "" python main.py
    exit /b 0
)

where py >nul 2>nul
if %errorlevel% equ 0 (
    start "" py main.py
    exit /b 0
)

if exist "C:\Users\LENOVO\AppData\Local\Programs\Python\Python311\python.exe" (
    start "" "C:\Users\LENOVO\AppData\Local\Programs\Python\Python311\python.exe" main.py
    exit /b 0
)

if exist "C:\Users\LENOVO\AppData\Local\Programs\Python\Python310\python.exe" (
    start "" "C:\Users\LENOVO\AppData\Local\Programs\Python\Python310\python.exe" main.py
    exit /b 0
)

if exist "C:\Users\LENOVO\AppData\Local\Programs\Python\Python312\python.exe" (
    start "" "C:\Users\LENOVO\AppData\Local\Programs\Python\Python312\python.exe" main.py
    exit /b 0
)

echo Python is not installed on this machine.
echo Please install Python 3 or notify administrator.
pause
