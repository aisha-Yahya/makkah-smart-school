@echo off
chcp 65001 >nul
cd /d "%~dp0"
title تثبيت المزامنة التلقائية الصامتة مع تشغيل الكمبيوتر

echo ==============================================================
echo   مدارس مكة الأهلية الحديثة - تثبيت المزامنة السحابية اللحظية
echo   تشغيل المزامنة الصامتة تلقائياً عند إقلاع الويندوز
echo ==============================================================
echo.

set "TARGET_VBS=%~dp0start_cloud_watcher_silent.vbs"
set "STARTUP_DIR=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
set "SHORTCUT_PATH=%STARTUP_DIR%\MakkahSchoolCloudWatcher.lnk"

powershell -Command "$ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut('%SHORTCUT_PATH%'); $s.TargetPath = 'wscript.exe'; $s.Arguments = '\"%TARGET_VBS%\"'; $s.WorkingDirectory = '%~dp0'; $s.Save()"

if exist "%SHORTCUT_PATH%" (
    echo [نجاح] تم تثبيت المزامنة التلقائية بنجاح!
    echo الآن بمجرد تشغيل كمبيوتر المدرسة صباحاً:
    echo سيعمل المراقب بالخلفية تلقائياً دون إظهار أي شاشات سوداء.
    echo وأي تعديل أو إضافة في سكول برو سيرتفع فوراً إلى السحابة.
) else (
    echo [تنبيه] يرجى تشغيل الملف كمسؤول Administrator إذا ظهر خطأ.
)

echo.
echo اضغط أي مفتاح للبدء في تشغيل المزامنة الآن...
start "" wscript.exe "%TARGET_VBS%"
pause
