$connStr = "Server=.\SQLEXPRESS;AttachDbFilename=D:\Friends Soft\School Pro\DB\sdb.mdf;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
$conn.Open()

$cmd = $conn.CreateCommand()
$cmd.CommandText = "SELECT COUNT(*) FROM account"
$cnt = $cmd.ExecuteScalar()
Write-Output "Total rows in account: $cnt"

$cmd2 = $conn.CreateCommand()
$cmd2.CommandText = "SELECT TOP 10 acc_no, mo_st_no, acc_cost, cnst_menual_no FROM account ORDER BY acc_no DESC"
$r = $cmd2.ExecuteReader()
while ($r.Read()) {
    Write-Output "ACC -> acc_no: $($r['acc_no']) | mo_st_no: $($r['mo_st_no']) | cost: $($r['acc_cost']) | receipt: $($r['cnst_menual_no'])"
}
$r.Close()
$conn.Close()
