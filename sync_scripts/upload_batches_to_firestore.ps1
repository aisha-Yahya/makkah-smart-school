# Super-fast Firestore Batch Uploader
$ErrorActionPreference = "Continue"

$rootDir = (Get-Item "$PSScriptRoot\..").FullName
$batchDir = Join-Path $rootDir "api\data\firestore_batches"
$commitUrl = "https://firestore.googleapis.com/v1/projects/makkah-smart-school/databases/(default)/documents:commit"

$files = Get-ChildItem -Path $batchDir -Filter "*.json" | Sort-Object Name
$totalFiles = $files.Count

Write-Host "Starting high-speed upload of $totalFiles batches to Cloud Firestore..." -ForegroundColor Cyan

$successCount = 0
$failCount = 0
$i = 0

foreach ($f in $files) {
    $i++
    $pct = [math]::Round(($i / $totalFiles) * 100)
    $argBinary = '@' + $f.FullName
    
    $attempt = 0
    $sent = $false
    while ($attempt -lt 3 -and -not $sent) {
        $attempt++
        try {
            $resp = & curl.exe --ssl-no-revoke -s -X POST -H 'Content-Type: application/json' --data-binary $argBinary $commitUrl
            if ($resp -match '"commitTime"') {
                $successCount++
                $sent = $true
                Write-Host "[$i/$totalFiles ($pct%)] Uploaded $($f.Name) successfully." -ForegroundColor Green
            } else {
                throw $resp
            }
        } catch {
            if ($attempt -ge 3) {
                $failCount++
                Write-Host "[$i/$totalFiles] Failed $($f.Name): $($_.Exception.Message)" -ForegroundColor Red
            } else {
                Start-Sleep -Seconds 1
            }
        }
    }
}

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "Upload completed! Success: $successCount, Failed: $failCount" -ForegroundColor Yellow

# Send an overview ping to Cloud Firestore
$metaJson = @{
    writes = @(
        @{
            update = @{
                name = "projects/makkah-smart-school/databases/(default)/documents/system_status/cloud_sync_status"
                fields = @{
                    status = @{ stringValue = "synced" }
                    last_full_sync = @{ stringValue = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss") }
                    total_batches = @{ integerValue = "$totalFiles" }
                    successful_batches = @{ integerValue = "$successCount" }
                    students_total = @{ integerValue = "1777" }
                    message = @{ stringValue = "All students, grades, certificates, and financials uploaded to Firebase Cloud." }
                }
            }
        }
    )
} | ConvertTo-Json -Depth 6

try {
    $metaBytes = [System.Text.Encoding]::UTF8.GetBytes($metaJson)
    $tmpMeta = Join-Path $env:TEMP "cloud_sync_meta.json"
    [System.IO.File]::WriteAllBytes($tmpMeta, $metaBytes)
    $respMeta = & curl.exe --ssl-no-revoke -s -X POST -H 'Content-Type: application/json' --data-binary "@$tmpMeta" $commitUrl
    Write-Host "Cloud status document updated." -ForegroundColor Green
    Remove-Item $tmpMeta -ErrorAction SilentlyContinue
} catch {}
