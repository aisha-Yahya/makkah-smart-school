$connStr = "Server=.\SQLEXPRESS;AttachDbFilename=D:\Friends Soft\School Pro\DB\sdb.mdf;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
$conn.Open()

$cmd = $conn.CreateCommand()
$cmd.CommandText = "SELECT COUNT(*) FROM student_data"
$cnt = $cmd.ExecuteScalar()
Write-Output "Total students in SQL Server: $cnt"

$conn.Close()
