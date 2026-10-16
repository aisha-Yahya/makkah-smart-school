<?php
/**
 * استرجاع السجلات المالية وكشوفات الحساب
 * GET /api/financials.php?student_id=...&type=transactions|summary|due
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/response.php';

$pdo = getDbConnection();

$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : null;
$type = isset($_GET['type']) ? trim($_GET['type']) : 'all';

if ($studentId) {
    // كشف حساب طالب محدد
    $accStmt = $pdo->prepare("SELECT * FROM student_account_summary WHERE student_id = ?");
    $accStmt->execute([$studentId]);
    $summary = $accStmt->fetch();

    $transStmt = $pdo->prepare("
        SELECT * FROM financial_transactions 
        WHERE student_id = ? 
        ORDER BY trans_date DESC, trans_id DESC
    ");
    $transStmt->execute([$studentId]);
    $transactions = $transStmt->fetchAll();

    jsonResponse(true, [
        "student_id"   => $studentId,
        "summary"      => $summary,
        "transactions" => $transactions
    ], "تم جلب كشف الحساب المالي بنجاح");
}

// نظرة عامة شاملة لكل الحسابات
$statsStmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT student_id) as total_students_with_accounts,
        SUM(total_fees) as sum_total_fees,
        SUM(total_paid) as sum_total_paid,
        SUM(remaining_balance) as sum_remaining_balance
    FROM student_account_summary
");
$stats = $statsStmt->fetch();

$summaryListStmt = $pdo->query("
    SELECT * FROM student_account_summary 
    ORDER BY remaining_balance DESC 
    LIMIT 100
");
$summaries = $summaryListStmt->fetchAll();

jsonResponse(true, [
    "overview"  => $stats,
    "accounts"  => $summaries
], "تم جلب البيانات المالية الإجمالية");
