# Real-time Cloud Watcher for School Pro
# Monitors sdb.mdf and synchronizes to Cloud Firestore automatically on every change

$ErrorActionPreference = "Continue"

$rootDir = (Get-Item "$PSScriptRoot\..").FullName
$configFile = Join-Path $rootDir "api\data\db_config.json"
$phpExe = Join-Path $rootDir "php\php.exe"

$mdfPath = "D:\Friends Soft\School Pro\DB\sdb.mdf"
if (Test-Path $configFile) {
    try {
        $cfg = Get-Content $configFile -Raw | ConvertFrom-Json
        if ($cfg.mdf_path -and (Test-Path $cfg.mdf_path)) {
            $mdfPath = $cfg.mdf_path
        }
    } catch {}
}

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "  Makkah Smart School - Real-Time Cloud Watcher" -ForegroundColor Yellow
Write-Host "  Monitoring Database: $mdfPath" -ForegroundColor White
Write-Host "==========================================================" -ForegroundColor Cyan

$lastMtime = ""
if (Test-Path $mdfPath) {
    $lastMtime = (Get-Item $mdfPath).LastWriteTimeUtc.Ticks.ToString()
}

while ($true) {
    try {
        if (Test-Path $mdfPath) {
            $currentMtime = (Get-Item $mdfPath).LastWriteTimeUtc.Ticks.ToString()
            if ($lastMtime -ne "" -and $currentMtime -ne $lastMtime) {
                $lastMtime = $currentMtime
                $stamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
                Write-Host "[$stamp] Detected change in School Pro database!" -ForegroundColor Green
                Write-Host "[$stamp] Step 1: Syncing SQL Server to Platform SQLite..." -ForegroundColor Yellow
                
                & powershell -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot "sync_school_pro.ps1") | Out-Null
                
                Write-Host "[$stamp] Step 2: Generating Cloud Firestore batches & offline dataset..." -ForegroundColor Yellow
                & $phpExe (Join-Path $PSScriptRoot "export_complete_firestore_payload.php") | Out-Null
                
                Write-Host "[$stamp] Step 3: Uploading live changes to Firebase Cloud..." -ForegroundColor Yellow
                & powershell -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot "upload_batches_to_firestore.ps1") | Out-Null
                
                Write-Host "[$stamp] Synchronization complete! All changes are now live on Firebase." -ForegroundColor Green
            }
        }
    } catch {
        Write-Host "Watcher exception: $($_.Exception.Message)" -ForegroundColor Red
    }
    
    # Check every 15 seconds
    Start-Sleep -Seconds 15
}
