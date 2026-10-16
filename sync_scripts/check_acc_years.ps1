$connStr = "Server=.\SQLEXPRESS;AttachDbFilename=D:\Friends Soft\School Pro\DB\sdb.mdf;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
$conn.Open()

$cmd = $conn.CreateCommand()
$cmd.CommandText = "SELECT a.y_no, y.y_p1, COUNT(*) as cnt FROM account a LEFT JOIN [year] y ON a.y_no = y.y_no GROUP BY a.y_no, y.y_p1 ORDER BY a.y_no DESC"
$r = $cmd.ExecuteReader()
while ($r.Read()) {
    Write-Output "y_no: $($r['y_no']) | year: $($r['y_p1']) | cnt: $($r['cnt'])"
}
$r.Close()
$conn.Close()
