@echo off
chcp 65001 >nul
cd /d "%~dp0"
title Makkah School Pro Real-Time Cloud Watcher
echo ==============================================================
echo   Makkah Smart School - Real-Time School Pro Cloud Watcher
echo   المراقب السحابي اللحظي لنظام سكول برو - رفع التعديلات فورياً
echo ==============================================================
powershell -ExecutionPolicy Bypass -File "%~dp0sync_scripts\live_cloud_watcher.ps1"
pause
