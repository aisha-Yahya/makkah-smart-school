Set WshShell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
syncDir = scriptDir & "\school_pro_sync_tool"
WshShell.CurrentDirectory = syncDir

WshShell.Run "pythonw main.py", 0, False
