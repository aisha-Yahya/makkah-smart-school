$commitUrl = 'https://firestore.googleapis.com/v1/projects/makkah-smart-school/databases/(default)/documents:commit'
$failed = @()

foreach ($b in 58..72) {
    $fn = ('api/data/firestore_batches/batch_{0:D3}.json' -f $b)
    if (Test-Path $fn) {
        $bytes = [System.IO.File]::ReadAllBytes($fn)
        $success = $false
        for ($retry = 1; $retry -le 4; $retry++) {
            try {
                $resp = Invoke-RestMethod -Method Post -Uri $commitUrl -Body $bytes -ContentType 'application/json; charset=utf-8' -TimeoutSec 30
                Write-Host ("[SUCCESS] Batch {0} uploaded successfully" -f $b) -ForegroundColor Green
                $success = $true
                break
            } catch {
                Write-Host ("[RETRY {0}/4] Batch {1}: {2}" -f $retry, $b, $_.Exception.Message) -ForegroundColor Yellow
                Start-Sleep -Seconds 2
            }
        }
        if (-not $success) {
            $failed += $b
        }
    }
}

if ($failed.Count -eq 0) {
    Write-Host "All batches 58-72 uploaded successfully! 100% complete!" -ForegroundColor Cyan
} else {
    Write-Host ("Failed batches: " + ($failed -join ", ")) -ForegroundColor Red
}
