
$wsh = New-Object -ComObject WScript.Shell
$shortcut = $wsh.CreateShortcut('C:\Users\LENOVO\Desktop\SchoolPro_Sync.lnk')
$shortcut.TargetPath = 'wscript.exe'
$shortcut.Arguments = '"C:\Users\LENOVO\Desktop\makkah-smart-school\school_pro_sync_tool\run_silent.vbs"'
$shortcut.WorkingDirectory = 'C:\Users\LENOVO\Desktop\makkah-smart-school\school_pro_sync_tool'
$shortcut.IconLocation = 'C:\Users\LENOVO\Desktop\makkah-smart-school\school_pro_sync_tool\school_logo.ico, 0'
$shortcut.Description = 'School Pro Live Sync Tool'
$shortcut.Save()
