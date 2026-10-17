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

# If current mdfPath does not exist on this computer, auto-discover it!
if (-not (Test-Path $mdfPath)) {
    $candidates = @(
        "D:\Friends Soft\School Pro\DB\sdb.mdf",
        "E:\Friends Soft\School Pro\DB\sdb.mdf",
        "C:\Friends Soft\School Pro\DB\sdb.mdf",
        "D:\Friends Soft2\School Pro\DB\sdb.mdf",
        "E:\Friends Soft2\School Pro\DB\sdb.mdf",
        "C:\Friends Soft2\School Pro\DB\sdb.mdf",
        "D:\School Pro\DB\sdb.mdf",
        "C:\School Pro\DB\sdb.mdf"
    )
    foreach ($cand in $candidates) {
        if (Test-Path $cand) {
            $mdfPath = $cand
            break
        }
    }
}

$roleFile = Join-Path $rootDir "api\data\sync_role.json"
$syncRole = "all"
if (Test-Path $roleFile) {
    try {
        $rCfg = Get-Content $roleFile -Raw | ConvertFrom-Json
        if ($rCfg.role) { $syncRole = $rCfg.role }
    } catch {}
}

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "  Makkah Smart School - Real-Time Cloud Watcher" -ForegroundColor Yellow
Write-Host "  Role Mode: $syncRole" -ForegroundColor Green
Write-Host "  Monitoring Database: $mdfPath" -ForegroundColor White
Write-Host "==========================================================" -ForegroundColor Cyan

$lastMtime = ""
$initialSyncDone = $false

while ($true) {
    try {
        if (Test-Path $mdfPath) {
            $currentMtime = (Get-Item $mdfPath).LastWriteTimeUtc.Ticks.ToString()
            if (-not $initialSyncDone -or ($lastMtime -ne "" -and $currentMtime -ne $lastMtime)) {
                $lastMtime = $currentMtime
                $initialSyncDone = $true
                $stamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
                Write-Host "[$stamp] Syncing School Pro database ($syncRole)..." -ForegroundColor Green
                Write-Host "[$stamp] Step 1: Syncing SQL Server to Platform SQLite..." -ForegroundColor Yellow
                
                & powershell -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot "sync_school_pro.ps1") | Out-Null
                
                Write-Host "[$stamp] Step 2: Generating Cloud Firestore batches & offline dataset (Role: $syncRole)..." -ForegroundColor Yellow
                & $phpExe (Join-Path $PSScriptRoot "export_complete_firestore_payload.php") "--role=$syncRole" | Out-Null
                
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
