Set WshShell = CreateObject("WScript.Shell")
strCurDir = WshShell.CurrentDirectory
scriptPath = strCurDir & "\sync_scripts\live_cloud_watcher.ps1"
WshShell.Run "powershell.exe -WindowStyle Hidden -ExecutionPolicy Bypass -File """ & scriptPath & """", 0, False
