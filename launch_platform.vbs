Set WshShell = CreateObject("WScript.Shell")

' 1. Check if PHP server is listening on port 8000
Dim isRunning
isRunning = False

On Error Resume Next
Set http = CreateObject("MSXML2.ServerXMLHTTP.6.0")
http.Open "GET", "http://127.0.0.1:8000/api/config.php", False
http.setTimeouts 500, 500, 500, 500
http.Send
If http.Status = 200 Or http.Status = 404 Or http.Status = 403 Then
    isRunning = True
End If
On Error GoTo 0

' 2. If not running, start PHP server silently in the background (0 = completely hidden window)
If Not isRunning Then
    WshShell.Run """C:\tmp\php81\php.exe"" -S 127.0.0.1:8000 -t ""C:\Users\LENOVO\Desktop\makkah-smart-school""", 0, False
    WScript.Sleep 800
End If

' 3. Open default browser directly to the platform
WshShell.Run "http://127.0.0.1:8000"
