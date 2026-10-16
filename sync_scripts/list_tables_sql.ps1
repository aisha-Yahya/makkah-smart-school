$connStr = "Server=.\SQLEXPRESS;AttachDbFilename=D:\Friends Soft\School Pro\DB\sdb.mdf;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
$conn.Open()

$cmd = $conn.CreateCommand()
$cmd.CommandText = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
$r = $cmd.ExecuteReader()
while ($r.Read()) {
    Write-Output "$($r['TABLE_NAME'])"
}
$r.Close()
$conn.Close()
