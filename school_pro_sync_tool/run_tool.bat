@echo off
setlocal
cd /d "C:\Users\LENOVO\.gemini\antigravity\scratch\school_pro_sync_tool"
"C:\Users\LENOVO\.local\bin\uv.exe" run --with pyodbc --with requests python main.py
if errorlevel 1 pause
