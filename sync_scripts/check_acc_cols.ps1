$connStr = "Server=.\SQLEXPRESS;AttachDbFilename=D:\Friends Soft\School Pro\DB\sdb.mdf;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
$conn.Open()

$cmd = $conn.CreateCommand()
$cmd.CommandText = "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'account'"
$r = $cmd.ExecuteReader()
while ($r.Read()) {
    Write-Output "COL: $($r['COLUMN_NAME']) | TYPE: $($r['DATA_TYPE'])"
}
$r.Close()
$conn.Close()
