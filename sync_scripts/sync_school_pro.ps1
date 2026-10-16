# School Pro High-Speed Synchronizer to Platform SQLite
param(
    [string]$MdfPath = ""
)

$ErrorActionPreference = "Stop"

$rootDir = (Get-Item "$PSScriptRoot\..").FullName
$dataDir = Join-Path $rootDir "api\data"
$configFile = Join-Path $dataDir "db_config.json"

# Discover MDF if not specified
if (-not $MdfPath -or $MdfPath.Trim() -eq "") {
    if (Test-Path $configFile) {
        try {
            $cfg = Get-Content $configFile -Raw | ConvertFrom-Json
            if ($cfg.mdf_path -and (Test-Path $cfg.mdf_path)) {
                $MdfPath = $cfg.mdf_path
            }
        } catch {}
    }
}

if (-not $MdfPath -or -not (Test-Path $MdfPath)) {
    # Run auto-discovery script
    $discoverRes = & powershell -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot "find_mdf.ps1") | ConvertFrom-Json
    if ($discoverRes.success) {
        $MdfPath = $discoverRes.mdf_path
    } else {
        @{
            success = $false
            message = "Could not find School Pro sdb.mdf database file."
        } | ConvertTo-Json
        exit 1
    }
}

# Verify directory junction on E: if needed
$drive = (Split-Path -Qualifier $MdfPath).TrimEnd(':')
if ($drive -eq "D" -and -not (Test-Path "E:\Friends Soft") -and (Test-Path "D:\Friends Soft")) {
    try { cmd /c mklink /J "E:\Friends Soft" "D:\Friends Soft" | Out-Null } catch {}
}

$connStr = "Server=.\SQLEXPRESS;AttachDbFilename=$MdfPath;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)

try {
    $conn.Open()
} catch {
    # Fallback
    $connStr = "Server=.\SQLEXPRESS;AttachDbFilename=$MdfPath;Integrated Security=True;Connect Timeout=15;"
    $conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
    $conn.Open()
}

Write-Host "Extracting School Pro records from SQL Server..."

$utf8NoBom = [System.Text.UTF8Encoding]::new($false)

# 1. Seat numbers from student_move
$cmdSeats = $conn.CreateCommand()
$cmdSeats.CommandText = @"
SELECT 
    sm.st_no as student_id,
    sm.mo_st_no as registration_code,
    sm.set_no1 as seat_number
FROM student_move sm
INNER JOIN (
    SELECT st_no, MAX(y_no) as max_y, MAX(mo_st_no) as max_mo
    FROM student_move
    GROUP BY st_no
) latest ON sm.st_no = latest.st_no AND sm.mo_st_no = latest.max_mo
WHERE sm.set_no1 IS NOT NULL AND sm.set_no1 > 0
"@
$readerSeats = $cmdSeats.ExecuteReader()
$seatsList = [System.Collections.Generic.List[Object]]::new()
while ($readerSeats.Read()) {
    $seatsList.Add(@{
        student_id = [int]$readerSeats["student_id"]
        registration_code = "$($readerSeats["registration_code"])"
        seat_number = [double]$readerSeats["seat_number"]
    })
}
$readerSeats.Close()
[System.IO.File]::WriteAllText((Join-Path $dataDir "sync_seat_numbers.json"), ($seatsList | ConvertTo-Json -Depth 3), $utf8NoBom)
Write-Host "Seats extracted: $($seatsList.Count)"

# 2. Certificates (student_certificate_view)
$cmdCert = $conn.CreateCommand()
$cmdCert.CommandText = @"
SELECT 
    sc.st_no as student_id,
    sc.mo_st_no as registration_code,
    sc.st_ar_nm as student_name,
    sc.cl_no as class_id,
    c.cl_ar_nm as class_name,
    sc.y_no as year_id,
    y.y_p1 as academic_year,
    sc.sub_ar_nm as subject_name,
    sc.sub_order,
    ISNULL(sc.sub_deg_max, 100) as sub_deg_max,
    ISNULL(sc.term1_deg, 0) as term1_work,
    ISNULL(sc.fn1_deg, 0) as term1_exam,
    ISNULL(sc.term2_deg, 0) as term2_work,
    ISNULL(sc.fn2_deg, 0) as term2_exam,
    ISNULL(sc.final_sub_deg, 0) as final_mark,
    sc.sub_avreg
FROM student_certificate_view sc
LEFT JOIN classes c ON sc.cl_no = c.cl_no
LEFT JOIN [year] y ON sc.y_no = y.y_no
ORDER BY sc.y_no DESC, sc.st_no ASC, sc.sub_order ASC
"@
$readerCert = $cmdCert.ExecuteReader()
$certList = [System.Collections.Generic.List[Object]]::new()
while ($readerCert.Read()) {
    $certList.Add(@{
        student_id = [int]$readerCert["student_id"]
        registration_code = "$($readerCert["registration_code"])"
        student_name = "$($readerCert["student_name"])"
        class_id = if ($readerCert["class_id"] -is [DBNull]) { 0 } else { [int]$readerCert["class_id"] }
        class_name = "$($readerCert["class_name"])"
        year_id = if ($readerCert["year_id"] -is [DBNull]) { 0 } else { [int]$readerCert["year_id"] }
        academic_year = "$($readerCert["academic_year"])"
        subject_name = "$($readerCert["subject_name"])"
        sub_order = if ($readerCert["sub_order"] -is [DBNull]) { 0 } else { [int]$readerCert["sub_order"] }
        sub_deg_max = [double]$readerCert["sub_deg_max"]
        term1_work = [double]$readerCert["term1_work"]
        term1_exam = [double]$readerCert["term1_exam"]
        term2_work = [double]$readerCert["term2_work"]
        term2_exam = [double]$readerCert["term2_exam"]
        final_mark = [double]$readerCert["final_mark"]
        sub_avreg = "$($readerCert["sub_avreg"])"
    })
}
$readerCert.Close()
[System.IO.File]::WriteAllText((Join-Path $dataDir "sync_certificates.json"), ($certList | ConvertTo-Json -Depth 3), $utf8NoBom)
Write-Host "Certificates extracted: $($certList.Count)"

# 3. Annual Results & Ranks (sum_all_year_deg_view)
$cmdAnnual = $conn.CreateCommand()
$cmdAnnual.CommandText = @"
SELECT 
    sy.st_no as student_id,
    sy.mo_st_no as registration_code,
    sy.st_ar_nm as student_name,
    sy.cl_no as class_id,
    c.cl_ar_nm as class_name,
    sy.y_no as year_id,
    y.y_p1 as academic_year,
    sm.set_no1 as seat_number,
    ISNULL(sy.final_result, 0) as final_result,
    ISNULL(sy.sub_count, 0) as sub_count,
    ISNULL(sy.ratio, 0) as ratio,
    ISNULL(sy.min_deg, 0) as min_deg
FROM sum_all_year_deg_view sy
LEFT JOIN student_move sm ON sy.mo_st_no = sm.mo_st_no
LEFT JOIN classes c ON sy.cl_no = c.cl_no
LEFT JOIN [year] y ON sy.y_no = y.y_no
ORDER BY sy.y_no DESC, sy.st_no ASC
"@
$readerAnnual = $cmdAnnual.ExecuteReader()
$annualList = [System.Collections.Generic.List[Object]]::new()
while ($readerAnnual.Read()) {
    $annualList.Add(@{
        student_id = [int]$readerAnnual["student_id"]
        registration_code = "$($readerAnnual["registration_code"])"
        student_name = "$($readerAnnual["student_name"])"
        class_id = if ($readerAnnual["class_id"] -is [DBNull]) { 0 } else { [int]$readerAnnual["class_id"] }
        class_name = "$($readerAnnual["class_name"])"
        year_id = if ($readerAnnual["year_id"] -is [DBNull]) { 0 } else { [int]$readerAnnual["year_id"] }
        academic_year = "$($readerAnnual["academic_year"])"
        seat_number = if ($readerAnnual["seat_number"] -is [DBNull]) { 0 } else { [double]$readerAnnual["seat_number"] }
        final_result = [double]$readerAnnual["final_result"]
        sub_count = [int]$readerAnnual["sub_count"]
        ratio = [double]$readerAnnual["ratio"]
        min_deg = [double]$readerAnnual["min_deg"]
    })
}
$readerAnnual.Close()
[System.IO.File]::WriteAllText((Join-Path $dataDir "sync_annual_results.json"), ($annualList | ConvertTo-Json -Depth 3), $utf8NoBom)
Write-Host "Annual results extracted: $($annualList.Count)"

# 4. Detailed Monthly Grades (activity_deg)
$cmdMonth = $conn.CreateCommand()
$cmdMonth.CommandTimeout = 120
$cmdMonth.CommandText = @"
SELECT 
    sm.st_no as student_id,
    ad.mo_st_no as registration_code,
    sd.st_ar_nm as student_name,
    sp.cl_no as class_id,
    c.cl_ar_nm as class_name,
    sp.y_no as year_id,
    y.y_p1 as academic_year,
    sub.sub_ar_nm as subject_name,
    sub.sub_order,
    sp.sub_avreg,
    ad.term_no,
    ad.mnth_no as month_no,
    m.mnth_ar_nm as month_name,
    ISNULL(MAX(CASE WHEN ad.ac_no = 1 THEN ad.ad_deg END), 0) as hw_score,
    ISNULL(MAX(CASE WHEN ad.ac_no = 2 THEN ad.ad_deg END), 0) as att_score,
    ISNULL(MAX(CASE WHEN ad.ac_no = 3 THEN ad.ad_deg END), 0) as oral_score,
    ISNULL(MAX(CASE WHEN ad.ac_no = 4 THEN ad.ad_deg END), 0) as writ_score,
    ISNULL(SUM(ad.ad_deg), 0) as score
FROM activity_deg ad
JOIN study_plan sp ON ad.pln_no = sp.pln_no
JOIN subjects sub ON sp.sub_no = sub.sub_no
JOIN student_move sm ON ad.mo_st_no = sm.mo_st_no
JOIN student_data sd ON sm.st_no = sd.st_no
JOIN months m ON ad.mnth_no = m.mnth_no
JOIN classes c ON sp.cl_no = c.cl_no
JOIN [year] y ON sp.y_no = y.y_no
WHERE sp.y_no >= 14
GROUP BY sm.st_no, ad.mo_st_no, sd.st_ar_nm, sp.cl_no, c.cl_ar_nm, sp.y_no, y.y_p1, sub.sub_ar_nm, sub.sub_order, sp.sub_avreg, ad.term_no, ad.mnth_no, m.mnth_ar_nm
ORDER BY sp.y_no DESC, sm.st_no ASC, ad.term_no ASC, ad.mnth_no ASC, sub.sub_order ASC
"@
$readerMonth = $cmdMonth.ExecuteReader()
$monthList = [System.Collections.Generic.List[Object]]::new()
while ($readerMonth.Read()) {
    $monthList.Add(@{
        student_id = [int]$readerMonth["student_id"]
        registration_code = "$($readerMonth["registration_code"])"
        student_name = "$($readerMonth["student_name"])"
        class_id = if ($readerMonth["class_id"] -is [DBNull]) { 0 } else { [int]$readerMonth["class_id"] }
        class_name = "$($readerMonth["class_name"])"
        year_id = if ($readerMonth["year_id"] -is [DBNull]) { 0 } else { [int]$readerMonth["year_id"] }
        academic_year = "$($readerMonth["academic_year"])"
        subject_name = "$($readerMonth["subject_name"])"
        sub_order = if ($readerMonth["sub_order"] -is [DBNull]) { 0 } else { [int]$readerMonth["sub_order"] }
        sub_avreg = "$($readerMonth["sub_avreg"])"
        term_no = [int]$readerMonth["term_no"]
        month_no = [int]$readerMonth["month_no"]
        month_name = "$($readerMonth["month_name"])"
        hw_score = [double]$readerMonth["hw_score"]
        att_score = [double]$readerMonth["att_score"]
        oral_score = [double]$readerMonth["oral_score"]
        writ_score = [double]$readerMonth["writ_score"]
        score = [double]$readerMonth["score"]
    })
}
$readerMonth.Close()
[System.IO.File]::WriteAllText((Join-Path $dataDir "sync_monthly.json"), ($monthList | ConvertTo-Json -Depth 3), $utf8NoBom)
Write-Host "Detailed monthly records extracted: $($monthList.Count)"

# 5. Monthly Summaries & Ranks (sum_details_sub_month_view)
$cmdSum = $conn.CreateCommand()
$cmdSum.CommandTimeout = 120
$cmdSum.CommandText = @"
WITH Ranked AS (
    SELECT 
        s.st_no as student_id,
        s.mo_st_no as registration_code,
        s.st_ar_nm as student_name,
        s.cl_no as class_id,
        c.cl_ar_nm as class_name,
        s.y_no as year_id,
        y.y_p1 as academic_year,
        s.mnth_no as month_no,
        m.mnth_ar_nm as month_name,
        s.sub_count,
        s.final_sum,
        ROUND((CAST(s.final_sum AS FLOAT) / NULLIF(s.sub_count * 100, 0)) * 100, 2) as ratio,
        DENSE_RANK() OVER (PARTITION BY s.cl_no, s.y_no, s.mnth_no, sm.br_no ORDER BY s.final_sum DESC) as class_rank
    FROM sum_details_sub_month_view s
    JOIN student_move sm ON s.mo_st_no = sm.mo_st_no
    JOIN months m ON s.mnth_no = m.mnth_no
    JOIN classes c ON s.cl_no = c.cl_no
    JOIN [year] y ON s.y_no = y.y_no
    WHERE s.y_no >= 14
)
SELECT * FROM Ranked
ORDER BY year_id DESC, student_id ASC, month_no ASC
"@
$readerSum = $cmdSum.ExecuteReader()
$sumList = [System.Collections.Generic.List[Object]]::new()
while ($readerSum.Read()) {
    $sumList.Add(@{
        student_id = [int]$readerSum["student_id"]
        registration_code = "$($readerSum["registration_code"])"
        student_name = "$($readerSum["student_name"])"
        class_id = if ($readerSum["class_id"] -is [DBNull]) { 0 } else { [int]$readerSum["class_id"] }
        class_name = "$($readerSum["class_name"])"
        year_id = if ($readerSum["year_id"] -is [DBNull]) { 0 } else { [int]$readerSum["year_id"] }
        academic_year = "$($readerSum["academic_year"])"
        month_no = [int]$readerSum["month_no"]
        month_name = "$($readerSum["month_name"])"
        sub_count = [int]$readerSum["sub_count"]
        final_sum = [double]$readerSum["final_sum"]
        ratio = if ($readerSum["ratio"] -is [DBNull]) { 0 } else { [double]$readerSum["ratio"] }
        class_rank = [int]$readerSum["class_rank"]
    })
}
$readerSum.Close()
[System.IO.File]::WriteAllText((Join-Path $dataDir "sync_monthly_summaries.json"), ($sumList | ConvertTo-Json -Depth 3), $utf8NoBom)
Write-Host "Monthly summary records extracted: $($sumList.Count)"

$conn.Close()

# 6. Execute PHP ingestion into SQLite
$phpExe = Join-Path $rootDir "php\php.exe"
if (-not (Test-Path $phpExe)) {
    $phpExe = "php.exe"
}
$helperScript = Join-Path $rootDir "api\helpers\import_grades_to_sqlite.php"

Write-Host "Invoking PHP SQLite Ingestion..."
$ingestOutput = & $phpExe $helperScript
Write-Host $ingestOutput

@{
    success = $true
    mdf_path = $MdfPath
    seats_synced = $seatsList.Count
    certificates_synced = $certList.Count
    annual_results_synced = $annualList.Count
    monthly_grades_synced = $monthList.Count
    monthly_summaries_synced = $sumList.Count
    message = "Synchronization completed successfully."
} | ConvertTo-Json -Depth 5
