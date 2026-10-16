import os
import subprocess

tool_dir = r"C:\Users\LENOVO\Desktop\makkah-smart-school\school_pro_sync_tool"
desktop_dir = os.path.join(os.environ["USERPROFILE"], "Desktop")

# 1. Create run_silent.vbs
vbs_path = os.path.join(tool_dir, "run_silent.vbs")
vbs_content = f'''Set WshShell = CreateObject("WScript.Shell")
WshShell.Run """C:\\Users\\LENOVO\\.local\\bin\\uv.exe"" run --with pyodbc --with requests --with pillow python ""{tool_dir}\\main.py""", 0, False
'''
with open(vbs_path, "w", encoding="utf-8") as f:
    f.write(vbs_content)
print(f"Created: {vbs_path}")

# 2. Create run_app.bat (as fallback for user if desired)
bat_path = os.path.join(tool_dir, "run_app.bat")
bat_content = f'''@echo off
chcp 65001 >nul
title أداة مزامنة سكول برو - مدارس مكة الأهلية الحديثة
cd /d "{tool_dir}"
"C:\\Users\\LENOVO\\.local\\bin\\uv.exe" run --with pyodbc --with requests --with pillow python main.py
'''
with open(bat_path, "w", encoding="utf-8") as f:
    f.write(bat_content)
print(f"Created: {bat_path}")

# Also update the desktop bat file with a clean name
desktop_bat = os.path.join(desktop_dir, "تشغيل_مزامنة_سكول_برو.bat")
with open(desktop_bat, "w", encoding="utf-8") as f:
    f.write(bat_content)
print(f"Created: {desktop_bat}")

# Remove old generic bat if exists
old_bat = os.path.join(desktop_dir, "تشغيل_أداة_سكول_برو.bat")
if os.path.exists(old_bat):
    try:
        os.remove(old_bat)
        print(f"Removed old bat: {old_bat}")
    except Exception as e:
        print(f"Note: {e}")

# 3. Create Windows Shortcut (.lnk) on Desktop with School Logo Icon
ico_path = os.path.join(tool_dir, "school_logo.ico")
lnk_path = os.path.join(desktop_dir, "SchoolPro_Sync.lnk")

ps_script = f'''
$wsh = New-Object -ComObject WScript.Shell
$shortcut = $wsh.CreateShortcut('{lnk_path}')
$shortcut.TargetPath = 'wscript.exe'
$shortcut.Arguments = '"{vbs_path}"'
$shortcut.WorkingDirectory = '{tool_dir}'
$shortcut.IconLocation = '{ico_path}, 0'
$shortcut.Description = 'School Pro Live Sync Tool'
$shortcut.Save()
'''

ps_file = os.path.join(tool_dir, "create_shortcut.ps1")
with open(ps_file, "w", encoding="utf-8") as f:
    f.write(ps_script)

res = subprocess.run(["powershell", "-ExecutionPolicy", "Bypass", "-File", ps_file], capture_output=True, text=True)
print("PowerShell output:", res.stdout, res.stderr)
print(f"Shortcut exists on Desktop: {os.path.exists(lnk_path)}")
