$connStr = "Server=.\SQLEXPRESS;AttachDbFilename=D:\Friends Soft\School Pro\DB\sdb.mdf;Integrated Security=True;User Instance=True;Connect Timeout=15;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
$conn.Open()

$cmd = $conn.CreateCommand()
$cmd.CommandText = "SELECT sm.mo_st_no, sm.st_no, sm.cl_no, sm.y_no, sm.br_no, sd.st_ar_nm FROM student_move sm LEFT JOIN student_data sd ON sm.st_no = sd.st_no WHERE sm.mo_st_no LIKE '1611%' OR sm.st_no = 1611"
$r = $cmd.ExecuteReader()
while ($r.Read()) {
    Write-Output "mo_st_no: $($r['mo_st_no']) | st_no: $($r['st_no']) | cl_no: $($r['cl_no']) | y_no: $($r['y_no']) | name: $($r['st_ar_nm'])"
}
$r.Close()
$conn.Close()
