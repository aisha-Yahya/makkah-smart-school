$connStr = "Server=.\SQLEXPRESS;AttachDbFilename=D:\Friends Soft\School Pro\DB\sdb.mdf;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
$conn.Open()

$cmd = $conn.CreateCommand()
$cmd.CommandText = "SELECT st_no, st_ar_nm FROM student_data WHERE st_no = 1611 OR st_ar_nm LIKE '%اصيل%سمير%' OR st_ar_nm LIKE '%شذى%'"
$r = $cmd.ExecuteReader()
while ($r.Read()) {
    Write-Host "st_no: $($r['st_no']) | st_ar_nm: $($r['st_ar_nm'])"
}
$r.Close()

$cmd2 = $conn.CreateCommand()
$cmd2.CommandText = "SELECT sm.mo_st_no, sm.st_no, sm.cl_no, sm.y_no, sm.br_no, sm.set_no1, sd.st_ar_nm FROM student_move sm JOIN student_data sd ON sm.st_no = sd.st_no WHERE sm.st_no = 1611 OR sd.st_ar_nm LIKE '%اصيل%سمير%'"
$r2 = $cmd2.ExecuteReader()
while ($r2.Read()) {
    Write-Host "MOVE -> mo_st_no: $($r2['mo_st_no']) | st_no: $($r2['st_no']) | cl_no: $($r2['cl_no']) | y_no: $($r2['y_no']) | br_no: $($r2['br_no']) | set_no1: $($r2['set_no1']) | name: $($r2['st_ar_nm'])"
}
$r2.Close()
$conn.Close()
