<?php
/**
 * حالة المزامنة وإحصائيات السجلات في النظام مع دعم فلترة العام الدراسي
 * GET /api/sync_status.php?year=2023-2024
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/response.php';

$pdo = getDbConnection();

$selectedYear = isset($_GET['year']) ? trim($_GET['year']) : 'all';

// استخراج الأعوام الدراسية المتاحة في قاعدة البيانات
$yearsStmt = $pdo->query("SELECT DISTINCT SUBSTR(academic_year, 1, 9) as yr FROM financial_transactions WHERE academic_year IS NOT NULL AND academic_year != '' ORDER BY yr DESC");
$rawYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

$availableYears = [];
foreach ($rawYears as $y) {
    if (preg_match('/^\d{4}-\d{4}/', $y, $m)) {
        if (!in_array($m[0], $availableYears)) {
            $availableYears[] = $m[0];
        }
    }
}

if ($selectedYear !== 'all' && !empty($selectedYear)) {
    $paramYear = $selectedYear . '%';
    
    $stStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.student_id) 
        FROM students s
        WHERE s.student_id IN (SELECT student_id FROM student_enrollments WHERE academic_year LIKE ?)
           OR s.student_id IN (SELECT student_id FROM financial_transactions WHERE academic_year LIKE ?)
    ");
    $stStmt->execute([$paramYear, $paramYear]);
    $totalStudents = (int)$stStmt->fetchColumn();

    $transStmt = $pdo->prepare("SELECT COUNT(*) FROM financial_transactions WHERE fee_type_id = 1 AND academic_year LIKE ?");
    $transStmt->execute([$paramYear]);
    $totalTrans = (int)$transStmt->fetchColumn();

    $feesStmt = $pdo->prepare("SELECT SUM(amount) FROM financial_transactions WHERE acc_type_code = 2 AND fee_type_id = 1 AND academic_year LIKE ?");
    $feesStmt->execute([$paramYear]);
    $totalFees = (float)($feesStmt->fetchColumn() ?: 0);

    $paidStmt = $pdo->prepare("SELECT SUM(amount) FROM financial_transactions WHERE acc_type_code = 1 AND fee_type_id = 1 AND academic_year LIKE ?");
    $paidStmt->execute([$paramYear]);
    $totalPaid = (float)($paidStmt->fetchColumn() ?: 0);
} else {
    $totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    $totalTrans = (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE fee_type_id = 1")->fetchColumn();
    $totalFees = (float)($pdo->query("SELECT SUM(amount) FROM financial_transactions WHERE acc_type_code = 2 AND fee_type_id = 1")->fetchColumn() ?: 0);
    $totalPaid = (float)($pdo->query("SELECT SUM(amount) FROM financial_transactions WHERE acc_type_code = 1 AND fee_type_id = 1")->fetchColumn() ?: 0);
}

$totalGrades = (int)$pdo->query("SELECT COUNT(*) FROM student_grades")->fetchColumn();
$schoolInfo = $pdo->query("SELECT * FROM school_info LIMIT 1")->fetch();

// قراءة بيانات الميتا
$meta = [];
if (file_exists(SYNC_META_PATH)) {
    $meta = json_decode(file_get_contents(SYNC_META_PATH), true) ?: [];
}

jsonResponse(true, [
    "status" => "online",
    "is_synced" => ($totalStudents > 0),
    "school_info" => $schoolInfo ?: [
        "school_name" => "مدارس مكة الأهلية الحديثة",
        "city" => "محافظة إب"
    ],
    "selected_year" => $selectedYear,
    "available_years" => $availableYears,
    "statistics" => [
        "total_students" => $totalStudents,
        "total_transactions" => $totalTrans,
        "total_grades" => $totalGrades,
        "total_fees_amount" => $totalFees,
        "total_paid_amount" => $totalPaid,
        "total_remaining_amount" => ($totalFees - $totalPaid)
    ],
    "last_sync" => $meta
], "تم جلب حالة المزامنة بنجاح.");
