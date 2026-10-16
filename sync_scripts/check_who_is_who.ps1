$connStr = "Server=.\SQLEXPRESS;AttachDbFilename=D:\Friends Soft\School Pro\DB\sdb.mdf;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
$conn.Open()

$cmd = $conn.CreateCommand()
$cmd.CommandText = "SELECT st_no, st_ar_nm FROM student_data WHERE st_no IN (1611, 1718) OR st_ar_nm LIKE '%سهاد%'"
$r = $cmd.ExecuteReader()
while ($r.Read()) {
    $bytes = [System.Text.Encoding]::Unicode.GetBytes($r['st_ar_nm'])
    $hex = [System.BitConverter]::ToString($bytes)
    Write-Output "st_no: $($r['st_no']) | hex: $hex"
}
$r.Close()
$conn.Close()
