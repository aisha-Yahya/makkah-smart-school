$connStr = "Server=.\SQLEXPRESS;AttachDbFilename=D:\DB\sdb.mdf;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
try {
    $conn.Open()
    $cmd = $conn.CreateCommand()
    $cmd.CommandText = "SELECT COUNT(*), MAX(acc_no) FROM account"
    $r = $cmd.ExecuteReader()
    if ($r.Read()) {
        Write-Output "D:\DB\sdb.mdf: Count=$($r[0]), MaxAcc=$($r[1])"
    }
    $r.Close()
    $conn.Close()
} catch {
    Write-Output "Error: $_"
}
