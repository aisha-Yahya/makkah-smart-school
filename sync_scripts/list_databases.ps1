$connStr = "Server=.\SQLEXPRESS;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
try {
    $conn.Open()
    $cmd = $conn.CreateCommand()
    $cmd.CommandText = "SELECT name, physical_name FROM sys.master_files"
    $r = $cmd.ExecuteReader()
    while ($r.Read()) {
        Write-Output "DB File: $($r['name']) -> $($r['physical_name'])"
    }
    $r.Close()
    $conn.Close()
} catch {
    Write-Output "Error: $_"
}
