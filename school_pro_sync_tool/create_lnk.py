import os
import subprocess

tool_dir = r"C:\Users\LENOVO\.gemini\antigravity\scratch\school_pro_sync_tool"
ico_path = os.path.join(tool_dir, "school_logo.ico")
run_vbs = os.path.join(tool_dir, "run_silent.vbs")

# 1. Create run_silent.vbs (UTF-8)
with open(run_vbs, "w", encoding="utf-8") as f:
    f.write(f'''Set WshShell = CreateObject("WScript.Shell")
WshShell.Run """C:\\Users\\LENOVO\\.local\\bin\\uv.exe"" run --with pyodbc --with requests --with pillow python ""{tool_dir}\\main.py""", 0, False
''')

# 2. Create VBS to generate shortcut with Arabic name
vbs_gen = os.path.join(tool_dir, "mklink.vbs")
vbs_content = f'''Set oWS = CreateObject("WScript.Shell")
sDesk = oWS.SpecialFolders("Desktop")
sLink = sDesk & "\\مزامنة سكول برو - مدارس مكة.lnk"
Set oLink = oWS.CreateShortcut(sLink)
oLink.TargetPath = "wscript.exe"
oLink.Arguments = """{run_vbs}"""
oLink.WorkingDirectory = "{tool_dir}"
oLink.IconLocation = "{ico_path}, 0"
oLink.Description = "School Pro Sync - Makkah Schools"
oLink.Save
'''

# Windows Script Host accepts UTF-16 LE with BOM or ANSI
with open(vbs_gen, "w", encoding="utf-16") as f:
    f.write(vbs_content)

res = subprocess.run(["cscript", "//nologo", vbs_gen], capture_output=True, text=True)
print("Return code:", res.returncode)
print("Stdout:", res.stdout)
print("Stderr:", res.stderr)

desktop = os.path.join(os.environ["USERPROFILE"], "Desktop")
for f in os.listdir(desktop):
    if "مزامنة" in f or "سكول" in f:
        print("Found on Desktop:", f)
