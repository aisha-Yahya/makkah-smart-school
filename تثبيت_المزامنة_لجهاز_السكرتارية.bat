@echo off
chcp 65001 >nul
cd /d "%~dp0"
title تثبيت المزامنة التلقائية - جهاز السكرتارية

echo ==============================================================
echo   مدارس مكة الأهلية الحديثة - تثبيت مزامنة جهاز السكرتارية 📋
echo ==============================================================
echo.

if not exist "api\data" mkdir "api\data"
echo {"role": "secretariat"} > "api\data\sync_role.json"

set "TARGET_VBS=%~dp0start_cloud_watcher_silent.vbs"
set "STARTUP_DIR=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
set "SHORTCUT_PATH=%STARTUP_DIR%\MakkahSecretariatWatcher.lnk"

powershell -Command "$ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut('%SHORTCUT_PATH%'); $s.TargetPath = 'wscript.exe'; $s.Arguments = '\"%TARGET_VBS%\"'; $s.WorkingDirectory = '%~dp0'; $s.Save()"

echo [نجاح] تم تثبيت مزامنة السكرتارية بنجاح!
echo --------------------------------------------------------------
echo - الدور المحدد: [السكرتارية وشؤون الطلاب] 📋
echo - ما يتم رفعه: أسماء الطلاب، الفصول، الشهادات، درجات الشهور.
echo - الأمان والحماية: لن يتم التعديل على أو مسح بيانات المالية وسندات القبض أبداً.
echo - التشغيل: سيعمل بصمت في الخلفية تلقائياً كلما فتحت الكمبيوتر.
echo --------------------------------------------------------------
echo.
echo جاري بدء تشغيل المزامنة الآن بالخلفية...
start "" wscript.exe "%TARGET_VBS%"
echo تم التشغيل! اضغط أي زر للإغلاق.
pause >nul
