<?php
require_once __DIR__ . '/../config.php';
$pdo = getDbConnection();

$pdo->beginTransaction();

echo "1. Standardizing academic_year across all tables...\n";
$pdo->exec("UPDATE student_monthly_grades SET academic_year = '2026-2027 م' WHERE academic_year LIKE '%2026-2027%'");
$pdo->exec("UPDATE student_monthly_summaries SET academic_year = '2026-2027 م' WHERE academic_year LIKE '%2026-2027%'");
$pdo->exec("UPDATE student_certificate_grades SET academic_year = '2022-2023 م' WHERE academic_year LIKE '%2022-2023%'");
$pdo->exec("UPDATE student_year_results SET academic_year = '2022-2023 م' WHERE academic_year LIKE '%2022-2023%'");

echo "2. Ensuring student 1718 identity in students table...\n";
$st1718Exists = $pdo->query("SELECT COUNT(*) FROM students WHERE student_id = 1718")->fetchColumn();
if ($st1718Exists) {
    $pdo->exec("UPDATE students SET 
        full_name = 'أصيل سمير مرشد المطري',
        student_code = '1718017002',
        current_class_id = 2,
        current_class_name = 'الأول',
        branch_id = 1,
        branch_name = 'أ',
        academic_year = '2026-2027 م',
        seat_number = 50,
        status = 'مستمر'
        WHERE student_id = 1718");
} else {
    $pdo->exec("INSERT INTO students (student_id, student_code, full_name, current_class_id, current_class_name, branch_id, branch_name, academic_year, seat_number, status)
        VALUES (1718, '1718017002', 'أصيل سمير مرشد المطري', 2, 'الأول', 1, 'أ', '2026-2027 م', 50, 'مستمر')");
}

echo "3. Linking financials for student 1718...\n";
$fin1718Count = $pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE student_id = 1718")->fetchColumn();
if ($fin1718Count == 0) {
    // Copy financial transactions from 1611 to 1718
    $pdo->exec("INSERT INTO financial_transactions (student_id, registration_code, trans_type, acc_type_code, fee_type_id, fee_type_name, amount, receipt_no, trans_date, due_date, academic_year, month_no, notes)
        SELECT 1718, '1718017002', trans_type, acc_type_code, fee_type_id, fee_type_name, amount, receipt_no, trans_date, due_date, academic_year, month_no, notes
        FROM financial_transactions WHERE student_id = 1611");
    
    // Copy student_account_summary
    $pdo->exec("INSERT INTO student_account_summary (student_id, student_name, class_name, academic_year, total_fees, total_paid, remaining_balance, payment_status, last_payment_date)
        SELECT 1718, 'أصيل سمير مرشد المطري', class_name, academic_year, total_fees, total_paid, remaining_balance, payment_status, last_payment_date
        FROM student_account_summary WHERE student_id = 1611");
}

echo "4. Linking monthly grades for student 1611...\n";
$m1611Count = $pdo->query("SELECT COUNT(*) FROM student_monthly_grades WHERE student_id = 1611 AND subject_name != 'القرآن الكريم '")->fetchColumn();
if ($m1611Count == 0) {
    // Remove the secondary school mistaken entries for 1611
    $pdo->exec("DELETE FROM student_monthly_grades WHERE student_id = 1611 AND registration_code = '1611017012'");
    $pdo->exec("DELETE FROM student_monthly_summaries WHERE student_id = 1611 AND registration_code = '1611017012'");

    // Copy monthly grades from 1718 to 1611
    $pdo->exec("INSERT INTO student_monthly_grades (student_id, registration_code, student_name, class_id, class_name, year_id, academic_year, subject_name, sub_order, sub_avreg, term_no, month_no, month_name, hw_score, att_score, oral_score, writ_score, score)
        SELECT 1611, '1611017002', 'اصيل سمير مرشد المطري', class_id, class_name, year_id, academic_year, subject_name, sub_order, sub_avreg, term_no, month_no, month_name, hw_score, att_score, oral_score, writ_score, score
        FROM student_monthly_grades WHERE student_id = 1718");

    // Copy monthly summaries from 1718 to 1611
    $pdo->exec("INSERT INTO student_monthly_summaries (student_id, registration_code, student_name, class_id, class_name, year_id, academic_year, month_no, month_name, sub_count, final_sum, ratio, class_rank, rank_text, grade_estimate, status)
        SELECT 1611, '1611017002', 'اصيل سمير مرشد المطري', class_id, class_name, year_id, academic_year, month_no, month_name, sub_count, final_sum, ratio, class_rank, rank_text, grade_estimate, status
        FROM student_monthly_summaries WHERE student_id = 1718");
}

$pdo->commit();
echo "Done successfully!\n";
