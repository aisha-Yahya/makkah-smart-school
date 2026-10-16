$proc = Get-Process -Name php -ErrorAction SilentlyContinue
if (-not $proc) {
    Start-Process -FilePath "C:\tmp\php81\php.exe" -ArgumentList @("-S", "127.0.0.1:8000", "-t", "C:\Users\LENOVO\Desktop\makkah-smart-school") -WindowStyle Hidden
    Start-Sleep -Milliseconds 800
}
Start-Process "http://127.0.0.1:8000"
