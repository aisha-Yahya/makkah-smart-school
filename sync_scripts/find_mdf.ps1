param(
    [string]$CustomPath = "",
    [switch]$TestOnly = $false
)

$ErrorActionPreference = "SilentlyContinue"

$configFile = Join-Path $PSScriptRoot "..\api\data\db_config.json"
$configDir = Split-Path $configFile -Parent
if (-not (Test-Path $configDir)) {
    New-Item -ItemType Directory -Path $configDir -Force | Out-Null
}

function Test-MdfConnection([string]$mdfPath) {
    if (-not (Test-Path $mdfPath)) {
        return @{
            success = $false
            message = "File does not exist: $mdfPath"
        }
    }
    
    $drive = (Split-Path -Qualifier $mdfPath).TrimEnd(':')
    if ($drive -eq "D" -and -not (Test-Path "E:\Friends Soft") -and (Test-Path "D:\Friends Soft")) {
        try { cmd /c mklink /J "E:\Friends Soft" "D:\Friends Soft" | Out-Null } catch {}
    }

    $connCandidates = @(
        "Server=.\SQLEXPRESS;AttachDbFilename=$mdfPath;Integrated Security=True;User Instance=True;Connect Timeout=10;",
        "Server=.\SQLEXPRESS;AttachDbFilename=$mdfPath;Integrated Security=True;Connect Timeout=10;",
        "Server=.;AttachDbFilename=$mdfPath;Integrated Security=True;User Instance=True;Connect Timeout=10;",
        "Server=localhost\SQLEXPRESS;AttachDbFilename=$mdfPath;Integrated Security=True;User Instance=True;Connect Timeout=10;"
    )

    $lastErr = ""
    foreach ($cs in $connCandidates) {
        try {
            $conn = New-Object System.Data.SqlClient.SqlConnection($cs)
            $conn.Open()
            $cmd = $conn.CreateCommand()
            $cmd.CommandText = "SELECT COUNT(*) FROM student_data"
            $stCount = [int]$cmd.ExecuteScalar()

            $cmd2 = $conn.CreateCommand()
            $cmd2.CommandText = "SELECT COUNT(*) FROM student_certificate_view"
            $certCount = 0
            try { $certCount = [int]$cmd2.ExecuteScalar() } catch {}

            $conn.Close()
            return @{
                success = $true
                connection_string = $cs
                mdf_path = $mdfPath
                students_count = $stCount
                certificates_count = $certCount
                message = "Connected successfully to School Pro database."
            }
        } catch {
            $lastErr = $_.Exception.Message
        }
    }

    return @{
        success = $false
        message = "SQL Server connection failed: $lastErr"
    }
}

if ($CustomPath -and $CustomPath.Trim() -ne "") {
    $res = Test-MdfConnection -mdfPath $CustomPath.Trim()
    if ($res.success -and -not $TestOnly) {
        $cfg = @{
            mdf_path = $CustomPath.Trim()
            connection_string = $res.connection_string
            last_checked = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
            students_count = $res.students_count
            certificates_count = $res.certificates_count
        }
        $cfg | ConvertTo-Json -Depth 5 | Set-Content -Path $configFile -Encoding UTF8
    }
    $res | ConvertTo-Json -Depth 5
    exit 0
}

# Auto-Discovery: Check existing config first
if (Test-Path $configFile) {
    try {
        $saved = Get-Content $configFile -Raw | ConvertFrom-Json
        if ($saved.mdf_path -and (Test-Path $saved.mdf_path)) {
            $res = Test-MdfConnection -mdfPath $saved.mdf_path
            if ($res.success) {
                if (-not $TestOnly) {
                    $saved.last_checked = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
                    $saved.students_count = $res.students_count
                    $saved.certificates_count = $res.certificates_count
                    $saved | ConvertTo-Json -Depth 5 | Set-Content -Path $configFile -Encoding UTF8
                }
                $res | ConvertTo-Json -Depth 5
                exit 0
            }
        }
    } catch {}
}

# Priority Known Locations
$priorityPaths = @(
    "D:\Friends Soft\School Pro\DB\sdb.mdf",
    "E:\Friends Soft\School Pro\DB\sdb.mdf",
    "C:\Friends Soft\School Pro\DB\sdb.mdf",
    "D:\Friends Soft2\School Pro\DB\sdb.mdf",
    "D:\School Pro\DB\sdb.mdf",
    "C:\School Pro\DB\sdb.mdf"
)

foreach ($p in $priorityPaths) {
    if (Test-Path $p) {
        $res = Test-MdfConnection -mdfPath $p
        if ($res.success) {
            $cfg = @{
                mdf_path = $p
                connection_string = $res.connection_string
                last_checked = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
                students_count = $res.students_count
                certificates_count = $res.certificates_count
            }
            $cfg | ConvertTo-Json -Depth 5 | Set-Content -Path $configFile -Encoding UTF8
            $res | ConvertTo-Json -Depth 5
            exit 0
        }
    }
}

# Deep Scan across drives for newest sdb.mdf
$drives = Get-PSDrive -PSProvider FileSystem | Where-Object { $_.Free -gt 0 -or $_.Used -gt 0 } | ForEach-Object { "$($_.Name):\" }
$foundFiles = @()
foreach ($d in $drives) {
    try {
        $items = Get-ChildItem -Path $d -Filter "sdb.mdf" -Recurse -Depth 5 -ErrorAction SilentlyContinue |
                 Select-Object FullName, LastWriteTime, Length
        if ($items) { $foundFiles += $items }
    } catch {}
}

$sorted = $foundFiles | Sort-Object LastWriteTime -Descending
foreach ($f in $sorted) {
    $res = Test-MdfConnection -mdfPath $f.FullName
    if ($res.success) {
        $cfg = @{
            mdf_path = $f.FullName
            connection_string = $res.connection_string
            last_checked = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
            students_count = $res.students_count
            certificates_count = $res.certificates_count
        }
        $cfg | ConvertTo-Json -Depth 5 | Set-Content -Path $configFile -Encoding UTF8
        $res | ConvertTo-Json -Depth 5
        exit 0
    }
}

@{
    success = $false
    message = "No valid School Pro database (sdb.mdf) found automatically."
} | ConvertTo-Json -Depth 5
