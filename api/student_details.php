<?php
/**
 * استرجاع الملف الشامل للطالب (البيانات الشخصية + كشف الحساب المالي + كشف الدرجات)
 * GET /api/student_details.php?id=... أو ?code=...
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/response.php';

$pdo = getDbConnection();

$studentId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['student_id']) ? (int)$_GET['student_id'] : null);
$studentCode = isset($_GET['code']) ? trim($_GET['code']) : (isset($_GET['student_code']) ? trim($_GET['student_code']) : null);
$studentName = isset($_GET['name']) ? trim($_GET['name']) : null;

if (!$studentId && !$studentCode && !$studentName) {
    jsonResponse(false, null, "يرجى تحديد رقم الطالب (id) أو كود التسجيل (code) أو اسم الطالب (name)", 400);
}

// 1. جلب بيانات الطالب
$student = null;

// إذا تم توفير الاسم، البحث بالاسم أولاً بمطابقة عربية مرنة وموحدة
if ($studentName) {
    $normName = normalizeArabicText($studentName);
    
    // 1. تطابق كامل بالاسم الموحد
    $stStmt = $pdo->prepare("SELECT * FROM students WHERE norm_ar(full_name) = ? OR norm_ar(full_name) = norm_ar(?) LIMIT 1");
    $stStmt->execute([$normName, $studentName]);
    $student = $stStmt->fetch();

    if (!$student) {
        // 2. تطابق جزئي LIKE مع الاسم الموحد
        $stStmt = $pdo->prepare("SELECT * FROM students WHERE norm_ar(full_name) LIKE ? LIMIT 1");
        $stStmt->execute(["%{$normName}%"]);
        $student = $stStmt->fetch();
    }

    if (!$student) {
        // 3. مطابقة أول 3 مقاطع
        $parts = explode(' ', $normName);
        if (count($parts) >= 3) {
            $first3 = $parts[0] . ' ' . $parts[1] . ' ' . $parts[2];
            $stStmt = $pdo->prepare("SELECT * FROM students WHERE norm_ar(full_name) LIKE ? LIMIT 1");
            $stStmt->execute(["%{$first3}%"]);
            $student = $stStmt->fetch();
        }
    }

    if (!$student) {
        // 4. مطابقة أول مقطعين مع اللقب الأخير
        $parts = explode(' ', $normName);
        if (count($parts) >= 3) {
            $first2 = $parts[0] . ' ' . $parts[1];
            $last = end($parts);
            $stStmt = $pdo->prepare("SELECT * FROM students WHERE norm_ar(full_name) LIKE ? AND norm_ar(full_name) LIKE ? LIMIT 1");
            $stStmt->execute(["%{$first2}%", "%{$last}%"]);
            $student = $stStmt->fetch();
        }
    }
}

if (!$student && $studentCode) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_code = ? OR student_id = ? LIMIT 1");
    $stmt->execute([$studentCode, (int)$studentCode]);
    $student = $stmt->fetch();
}

if (!$student && $studentId) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ? LIMIT 1");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
}

if (!$student) {
    jsonResponse(false, null, "لم يتم العثور على الطالب في قاعدة البيانات المزمنة", 404);
}

$actualStudentId = (int)$student['student_id'];
$altStudentId = ($actualStudentId == 1611 ? 1718 : ($actualStudentId == 1718 ? 1611 : $actualStudentId));
$studentCode = $student['student_code'] ?? '';

require_once __DIR__ . '/helpers/class_formatter.php';
$student['raw_class_name'] = $student['current_class_name'] ?? '';
$student['current_class_name'] = formatPlatformClassName($student['current_class_name'] ?? '', $student['branch_name'] ?? '');
$student['full_class_name'] = $student['current_class_name'];

// 2. ملخص الحساب المالي (مرتبط بحزم بنفس العام الدراسي المختار دون أي تداخل بين السنوات)
$reqYear = isset($_GET['year']) ? trim($_GET['year']) : null;
$selectedYear = ($reqYear && $reqYear !== 'all') ? $reqYear : ($student['academic_year'] ?: '2026-2027 م');
$searchYearPattern = (preg_match('/(\d{4}\s*-\s*\d{4})/', $selectedYear, $ym)) ? preg_replace('/\s+/', '', $ym[1]) : $selectedYear;

$calcStmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN acc_type_code = 2 AND fee_type_id = 1 THEN amount ELSE 0 END) as total_fees,
        SUM(CASE WHEN acc_type_code = 1 AND fee_type_id = 1 THEN amount ELSE 0 END) as total_paid,
        SUM(CASE WHEN acc_type_code = 2 AND fee_type_id = 1 THEN amount ELSE 0 END) - SUM(CASE WHEN acc_type_code = 1 AND fee_type_id = 1 THEN amount ELSE 0 END) as remaining_balance,
        MAX(CASE WHEN acc_type_code = 1 AND fee_type_id = 1 THEN trans_date ELSE NULL END) as last_payment_date
    FROM financial_transactions 
    WHERE (student_id = ? OR student_id = ? OR registration_code = ?) AND academic_year LIKE ?
");
$calcStmt->execute([$actualStudentId, $altStudentId, $studentCode, "%{$searchYearPattern}%"]);
$calc = $calcStmt->fetch();

$totalF = (float)($calc['total_fees'] ?? 0);
$totalP = (float)($calc['total_paid'] ?? 0);
$rem = (float)($calc['remaining_balance'] ?? 0);

$accountSummary = [
    "student_id"        => $actualStudentId,
    "academic_year"     => $selectedYear,
    "total_fees"        => $totalF,
    "total_paid"        => $totalP,
    "remaining_balance" => $rem,
    "payment_status"    => ($totalF == 0 ? "بدون رسوم" : ($rem <= 0 ? "خالص الرسوم" : ($totalP > 0 ? "متبقي عليه" : "لم يسدد"))),
    "last_payment_date" => $calc['last_payment_date'] ?? null
];

$transStmt = $pdo->prepare("
    SELECT 
        trans_id, trans_type, acc_type_code, fee_type_id, fee_type_name,
        amount, receipt_no, trans_date, due_date, academic_year, month_no, notes
    FROM financial_transactions 
    WHERE (student_id = ? OR student_id = ? OR registration_code = ?) AND academic_year LIKE ? AND fee_type_id = 1
    ORDER BY trans_date DESC, trans_id DESC
");
$transStmt->execute([$actualStudentId, $altStudentId, $studentCode, "%{$searchYearPattern}%"]);
$transactions = $transStmt->fetchAll();


// 4. سجل الدرجات والنتائج
$gradesStmt = $pdo->prepare("
    SELECT 
        grade_id,
        subject_id,
        subject_name,
        term_no,
        term_name,
        term_work_score,
        final_exam_score,
        total_score,
        max_score,
        grade_estimate,
        academic_year
    FROM student_grades 
    WHERE student_id = ? 
    ORDER BY term_no ASC, subject_name ASC
");
$gradesStmt->execute([$actualStudentId]);
$grades = $gradesStmt->fetchAll();

// إحصائيات الدرجات
$gradesStats = [
    "total_subjects" => count($grades),
    "average_score"  => 0,
    "general_estimate" => "ممتاز"
];
if (count($grades) > 0) {
    $sum = 0;
    foreach ($grades as $g) {
        $sum += (float)$g['total_score'];
    }
    $avg = round($sum / count($grades), 2);
    $gradesStats['average_score'] = $avg;
    if ($avg >= 90) $gradesStats['general_estimate'] = "ممتاز";
    elseif ($avg >= 80) $gradesStats['general_estimate'] = "جيد جداً";
    elseif ($avg >= 65) $gradesStats['general_estimate'] = "جيد";
    elseif ($avg >= 50) $gradesStats['general_estimate'] = "مقبول";
    else $gradesStats['general_estimate'] = "راسب / دور ثاني";
}

// 5. خطة الأقساط الخاصة بصف الطالب
$plan = [];
if (!empty($student['current_class_id'])) {
    $planStmt = $pdo->prepare("
        SELECT * FROM fee_installments_plan 
        WHERE class_id = ? 
        ORDER BY installment_order ASC
    ");
    $planStmt->execute([(int)$student['current_class_id']]);
    $plan = $planStmt->fetchAll();
}

jsonResponse(true, [
    "student"          => $student,
    "account_summary"  => $accountSummary,
    "transactions"     => $transactions,
    "grades"           => $grades,
    "grades_stats"     => $gradesStats,
    "installments_plan"=> $plan
], "تم جلب بيانات الطالب بنجاح");
