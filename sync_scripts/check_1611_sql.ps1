$connStr = "Server=.\SQLEXPRESS;AttachDbFilename=D:\Friends Soft\School Pro\DB\sdb.mdf;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
$conn.Open()

$cmd = $conn.CreateCommand()
$cmd.CommandText = "SELECT st_no, st_ar_nm FROM student_data WHERE st_no = 1611"
$r = $cmd.ExecuteReader()
if ($r.Read()) {
    $bytes = [System.Text.Encoding]::Unicode.GetBytes($r['st_ar_nm'])
    $hex = [System.BitConverter]::ToString($bytes)
    Write-Output "SQL SERVER st_no 1611 Name Bytes: $hex"
}
$r.Close()

$cmd2 = $conn.CreateCommand()
$cmd2.CommandText = "SELECT st_no, st_ar_nm FROM student_data WHERE st_no IN (SELECT st_no FROM student_data WHERE st_ar_nm LIKE '%1611%' OR st_no = 1611)"
$r2 = $cmd2.ExecuteReader()
while ($r2.Read()) {
    Write-Output "st_no: $($r2['st_no'])"
}
$r2.Close()

$conn.Close()
