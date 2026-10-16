$connStr = "Server=.\SQLEXPRESS;AttachDbFilename=D:\Friends Soft\School Pro\DB\sdb.mdf;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
$conn.Open()

# Construct search using NCHAR
$cmd = $conn.CreateCommand()
$cmd.CommandText = "SELECT st_no, st_ar_nm FROM student_data WHERE st_ar_nm LIKE N'%" + [char]0x0627 + [char]0x0644 + [char]0x0645 + [char]0x0637 + [char]0x0631 + [char]0x064A + "%'"
$r = $cmd.ExecuteReader()
while ($r.Read()) {
    $bytes = [System.Text.Encoding]::Unicode.GetBytes($r['st_ar_nm'])
    Write-Output "FOUND ALMATARI: st_no: $($r['st_no']) | hex: $([System.BitConverter]::ToString($bytes))"
}
$r.Close()

$cmd2 = $conn.CreateCommand()
$cmd2.CommandText = "SELECT st_no, st_ar_nm FROM student_data WHERE st_ar_nm LIKE N'%" + [char]0x0633 + [char]0x0645 + [char]0x064A + [char]0x0631 + "%'"
$r2 = $cmd2.ExecuteReader()
while ($r2.Read()) {
    $bytes = [System.Text.Encoding]::Unicode.GetBytes($r2['st_ar_nm'])
    Write-Output "FOUND SAMEER: st_no: $($r2['st_no']) | hex: $([System.BitConverter]::ToString($bytes))"
}
$r2.Close()

$conn.Close()
