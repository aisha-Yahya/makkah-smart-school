$commitUrl = 'https://firestore.googleapis.com/v1/projects/makkah-smart-school/databases/(default)/documents:commit'

for ($b = 59; $b -le 72; $b++) {
    $fn = ('api/data/firestore_batches/batch_{0:D3}.json' -f $b)
    if (-not (Test-Path $fn)) {
        continue
    }

    $argBinary = '@' + $fn
    Write-Host ("[UPLOADING] Batch {0}/72..." -f $b) -ForegroundColor Cyan
    $res = & curl.exe --ssl-no-revoke -s -X POST -H 'Content-Type: application/json' --data-binary $argBinary $commitUrl
    
    if ($res -match '"commitTime"') {
        Write-Host ("[SUCCESS] Batch {0}/72 committed to Firestore!" -f $b) -ForegroundColor Green
    } else {
        Write-Host ("[FAILED] Batch {0}: {1}" -f $b, ($res | Out-String).Substring(0, [Math]::Min(150, ($res | Out-String).Length))) -ForegroundColor Red
        # Retry once
        Start-Sleep -Seconds 2
        $res = & curl.exe --ssl-no-revoke -s -X POST -H 'Content-Type: application/json' --data-binary $argBinary $commitUrl
        if ($res -match '"commitTime"') {
            Write-Host ("[SUCCESS RETRY] Batch {0}/72 committed!" -f $b) -ForegroundColor Green
        }
    }
}

Write-Host "==========================================" -ForegroundColor Green
Write-Host "ALL FIRESTORE BATCHES (1 to 72) COMPLETED!" -ForegroundColor Green
Write-Host "==========================================" -ForegroundColor Green
