Set WshShell = CreateObject("WScript.Shell")

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

If Not isRunning Then
    WshShell.Run """C:\tmp\php81\php.exe"" -S 127.0.0.1:8000 -t ""C:\Users\LENOVO\Desktop\makkah-smart-school""", 0, False
End If
