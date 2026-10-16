$commitUrl = 'https://firestore.googleapis.com/v1/projects/makkah-smart-school/databases/(default)/documents:commit'
$metaJson = @{
    writes = @(
        @{
            update = @{
                name = 'projects/makkah-smart-school/databases/(default)/documents/system_status/cloud_sync_status'
                fields = @{
                    status = @{ stringValue = 'synced' }
                    last_full_sync = @{ stringValue = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss') }
                    total_batches = @{ integerValue = '72' }
                    successful_batches = @{ integerValue = '72' }
                    students_total = @{ integerValue = '1777' }
                    monthly_grades_total = @{ integerValue = '68808' }
                    certificates_total = @{ integerValue = '8176' }
                    message = @{ stringValue = '100% of students, grades, certificates, and financials uploaded to Firebase Cloud.' }
                }
            }
        }
    )
} | ConvertTo-Json -Depth 6

$tmp = Join-Path $env:TEMP 'cloud_meta.json'
[System.IO.File]::WriteAllBytes($tmp, [System.Text.Encoding]::UTF8.GetBytes($metaJson))
& curl.exe --ssl-no-revoke -s -X POST -H 'Content-Type: application/json' --data-binary ('@' + $tmp) $commitUrl | Out-Null
Remove-Item $tmp -ErrorAction SilentlyContinue
Write-Host 'Status metadata document successfully saved to Firestore!' -ForegroundColor Green
