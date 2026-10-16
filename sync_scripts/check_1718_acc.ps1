$connStr = "Server=.\SQLEXPRESS;AttachDbFilename=D:\Friends Soft\School Pro\DB\sdb.mdf;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
$conn.Open()

$cmd = $conn.CreateCommand()
$cmd.CommandText = "SELECT mo_st_no, st_no, cl_no, y_no, br_no, set_no1 FROM student_move WHERE st_no = 1718"
$r = $cmd.ExecuteReader()
while ($r.Read()) {
    Write-Output "MOVE 1718 -> mo_st_no: $($r['mo_st_no']) | st_no: $($r['st_no']) | cl_no: $($r['cl_no']) | y_no: $($r['y_no']) | br_no: $($r['br_no']) | set_no1: $($r['set_no1'])"
}
$r.Close()

$cmd2 = $conn.CreateCommand()
$cmd2.CommandText = "SELECT a.acc_no, a.mo_st_no, a.acc_cost, a.cnst_menual_no FROM account a WHERE a.mo_st_no LIKE '1718%'"
$r2 = $cmd2.ExecuteReader()
while ($r2.Read()) {
    Write-Output "ACC 1718 -> acc_no: $($r2['acc_no']) | mo_st_no: $($r2['mo_st_no']) | cost: $($r2['acc_cost']) | receipt: $($r2['cnst_menual_no'])"
}
$r2.Close()
$conn.Close()
