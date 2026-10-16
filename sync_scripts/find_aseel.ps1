$connStr = "Server=.\SQLEXPRESS;AttachDbFilename=D:\Friends Soft\School Pro\DB\sdb.mdf;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
$conn.Open()

$cmd = $conn.CreateCommand()
$cmd.CommandText = "SELECT st_no, st_ar_nm FROM student_data"
$r = $cmd.ExecuteReader()
$found = @()
while ($r.Read()) {
    $name = [string]$r['st_ar_nm']
    if ($name -match 'سمير' -or $name -match 'المطري' -or $name -match 'اصيل') {
        $found += "$($r['st_no']): $name"
    }
}
$r.Close()

$found | ForEach-Object { Write-Output $_ }

$conn.Close()
