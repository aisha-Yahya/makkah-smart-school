@echo off
chcp 65001 >nul
cd /d "%~dp0"
title تثبيت المزامنة التلقائية - جهاز المالية والمحاسبة

echo ==============================================================
echo   مدارس مكة الأهلية الحديثة - تثبيت مزامنة جهاز المالية 💳
echo ==============================================================
echo.

if not exist "api\data" mkdir "api\data"
echo {"role": "finance"} > "api\data\sync_role.json"

set "TARGET_VBS=%~dp0start_cloud_watcher_silent.vbs"
set "STARTUP_DIR=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
set "SHORTCUT_PATH=%STARTUP_DIR%\MakkahFinanceWatcher.lnk"

powershell -Command "$ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut('%SHORTCUT_PATH%'); $s.TargetPath = 'wscript.exe'; $s.Arguments = '\"%TARGET_VBS%\"'; $s.WorkingDirectory = '%~dp0'; $s.Save()"

echo [نجاح] تم تثبيت مزامنة المالية بنجاح!
echo --------------------------------------------------------------
echo - الدور المحدد: [المالية والحسابات والرواتب] 💳
echo - ما يتم رفعه: الرسوم، المسدد، المتبقي، سندات القبض، رواتب المعلمين.
echo - الأمان والحماية: لن يتم التعديل على أو مسح بيانات الطلاب والدرجات للسكرتارية أبداً.
echo - التشغيل: سيعمل بصمت في الخلفية تلقائياً كلما فتحت الكمبيوتر.
echo --------------------------------------------------------------
echo.
echo جاري بدء تشغيل المزامنة الآن بالخلفية...
start "" wscript.exe "%TARGET_VBS%"
echo تم التشغيل! اضغط أي زر للإغلاق.
pause >nul
