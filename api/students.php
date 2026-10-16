<?php
/**
 * استرجاع قائمة الطلاب مع ملخص الحساب المالي لكل طالب مع دعم فلترة العام الدراسي
 * GET /api/students.php?q=...&class=...&year=2023-2024&status=...&limit=50&offset=0
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/response.php';
require_once __DIR__ . '/helpers/class_formatter.php';

$pdo = getDbConnection();

$action = $_GET['action'] ?? null;

// 1. استرجاع بيانات الطلاب والفصول حياً من قاعدة البيانات للمنصة المدرسية
if ($action === 'portal_data') {
    $rows = $pdo->query("
        SELECT student_id, student_code, full_name, current_class_id, current_class_name, branch_name, phone1, mobile, guardian_name, status, seat_number, academic_year 
        FROM students 
        WHERE full_name IS NOT NULL AND TRIM(full_name) != '' 
          AND (status = 'مستمر' OR status IS NULL OR status = '')
          AND current_class_name != 'التمهيدي'
        ORDER BY current_class_id ASC, branch_name ASC, full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $classesData = [];
    $studentsDB = [];

    foreach ($rows as $r) {
        $fullClass = formatPlatformClassName($r['current_class_name'], $r['branch_name']);
        $stName = trim($r['full_name']);

        if (!isset($classesData[$fullClass])) {
            $classesData[$fullClass] = [];
        }
        $classesData[$fullClass][] = $stName;

        $id = !empty($r['student_code']) ? $r['student_code'] : (string)$r['student_id'];
        $studentsDB[] = [
            "id" => $id,
            "student_id" => (int)$r['student_id'],
            "name" => $stName,
            "class" => $fullClass,
            "phone" => $r['mobile'] ?: ($r['phone1'] ?: ''),
            "guardian" => $r['guardian_name'] ?: '',
            "status" => "active",
            "seat_number" => $r['seat_number'] ?: '-',
            "academic_year" => $r['academic_year'] ?: '2026-2027'
        ];
    }

    jsonResponse(true, [
        "classesData" => $classesData,
        "studentsDB" => $studentsDB,
        "total_students" => count($studentsDB),
        "total_classes" => count($classesData)
    ], "تم جلب بيانات الطلاب والفصول حياً من قاعدة البيانات بنجاح");
}

// 2. عمليات الإضافة والحذف والتعديل المباشر في قاعدة البيانات
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;
    $postAction = $input['action'] ?? ($action ?? '');

    if ($postAction === 'add') {
        $name = trim($input['name'] ?? '');
        $class = trim($input['class'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $guardian = trim($input['guardian'] ?? '');

        if (empty($name)) {
            jsonResponse(false, null, "اسم الطالب مطلوب", 400);
        }

        $parsed = parsePlatformClassName($class ?: 'الصف الأول - أ');
        $cName = $parsed['class_name'];
        $bName = $parsed['branch_name'];

        $cidStmt = $pdo->prepare("SELECT current_class_id FROM students WHERE current_class_name = ? LIMIT 1");
        $cidStmt->execute([$cName]);
        $classId = (int)$cidStmt->fetchColumn();
        if (!$classId) $classId = 2; // Default to Class 1

        $maxId = (int)$pdo->query("SELECT MAX(student_id) FROM students")->fetchColumn();
        $newId = $maxId + 1;
        $newCode = $newId . "017" . str_pad($classId, 3, "0", STR_PAD_LEFT);

        $stmt = $pdo->prepare("
            INSERT INTO students (student_id, student_code, full_name, current_class_id, current_class_name, branch_name, phone1, mobile, guardian_name, status, academic_year)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'مستمر', '2026-2027 م')
        ");
        $stmt->execute([$newId, $newCode, $name, $classId, $cName, $bName, $phone, $phone, $guardian]);

        jsonResponse(true, [
            "student_id" => $newId,
            "student_code" => $newCode,
            "full_name" => $name,
            "class" => formatPlatformClassName($cName, $bName)
        ], "تمت إضافة الطالب في قاعدة البيانات بنجاح");
    }

    if ($postAction === 'delete') {
        $id = $input['id'] ?? ($input['student_id'] ?? null);
        $name = $input['name'] ?? null;

        if (!$id && !$name) {
            jsonResponse(false, null, "رقم أو اسم الطالب مطلوب للحذف", 400);
        }

        if ($id) {
            $stmt = $pdo->prepare("UPDATE students SET status = 'موقف' WHERE student_code = ? OR student_id = ?");
            $stmt->execute([$id, (int)$id]);
        } else {
            $stmt = $pdo->prepare("UPDATE students SET status = 'موقف' WHERE full_name = ?");
            $stmt->execute([$name]);
        }

        jsonResponse(true, null, "تم حذف/إيقاف قيد الطالب في قاعدة البيانات بنجاح");
    }

    if ($postAction === 'activate') {
        $id = $input['id'] ?? ($input['student_id'] ?? null);
        $name = $input['name'] ?? null;

        if (!$id && !$name) {
            jsonResponse(false, null, "رقم أو اسم الطالب مطلوب للتفعيل", 400);
        }

        if ($id) {
            $stmt = $pdo->prepare("UPDATE students SET status = 'مستمر' WHERE student_code = ? OR student_id = ?");
            $stmt->execute([$id, (int)$id]);
        } else {
            $stmt = $pdo->prepare("UPDATE students SET status = 'مستمر' WHERE full_name = ?");
            $stmt->execute([$name]);
        }

        jsonResponse(true, null, "تم تفعيل قيد الطالب في قاعدة البيانات بنجاح");
    }
}

$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$classFilter = isset($_GET['class']) ? trim($_GET['class']) : '';
$yearFilter = isset($_GET['year']) ? trim($_GET['year']) : 'all';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 50;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(s.full_name LIKE :search OR s.student_code LIKE :search OR s.student_id = :exact_id)";
    $params[':search'] = "%{$search}%";
    $params[':exact_id'] = is_numeric($search) ? (int)$search : -1;
}

$isYearFiltered = ($yearFilter !== 'all' && !empty($yearFilter));

if ($isYearFiltered) {
    $whereClauses[] = "(s.student_id IN (SELECT student_id FROM student_enrollments WHERE academic_year LIKE :year_param) OR s.student_id IN (SELECT student_id FROM financial_transactions WHERE academic_year LIKE :year_param))";
    $joinSql = "
        LEFT JOIN (
            SELECT 
                student_id,
                SUM(CASE WHEN acc_type_code = 2 AND fee_type_id = 1 THEN amount ELSE 0 END) as total_fees,
                SUM(CASE WHEN acc_type_code = 1 AND fee_type_id = 1 THEN amount ELSE 0 END) as total_paid,
                SUM(CASE WHEN acc_type_code = 2 AND fee_type_id = 1 THEN amount ELSE 0 END) - SUM(CASE WHEN acc_type_code = 1 AND fee_type_id = 1 THEN amount ELSE 0 END) as remaining_balance,
                MAX(CASE WHEN acc_type_code = 1 AND fee_type_id = 1 THEN trans_date ELSE NULL END) as last_payment_date
            FROM financial_transactions
            WHERE academic_year LIKE :year_param
            GROUP BY student_id
        ) ft ON s.student_id = ft.student_id
        LEFT JOIN (
            SELECT student_id, class_name, branch_name
            FROM student_enrollments
            WHERE academic_year LIKE :year_param
            GROUP BY student_id
        ) se ON s.student_id = se.student_id
    ";
    $params[':year_param'] = $yearFilter . '%';
} else {
    $joinSql = "
        LEFT JOIN (
            SELECT 
                student_id,
                SUM(CASE WHEN acc_type_code = 2 AND fee_type_id = 1 THEN amount ELSE 0 END) as total_fees,
                SUM(CASE WHEN acc_type_code = 1 AND fee_type_id = 1 THEN amount ELSE 0 END) as total_paid,
                SUM(CASE WHEN acc_type_code = 2 AND fee_type_id = 1 THEN amount ELSE 0 END) - SUM(CASE WHEN acc_type_code = 1 AND fee_type_id = 1 THEN amount ELSE 0 END) as remaining_balance,
                MAX(CASE WHEN acc_type_code = 1 AND fee_type_id = 1 THEN trans_date ELSE NULL END) as last_payment_date
            FROM financial_transactions
            GROUP BY student_id
        ) ft ON s.student_id = ft.student_id
    ";
}

if ($classFilter !== '') {
    if ($isYearFiltered) {
        $whereClauses[] = "(COALESCE(se.class_name, s.current_class_name) LIKE :class_filter)";
    } else {
        $whereClauses[] = "(s.current_class_name LIKE :class_filter)";
    }
    $params[':class_filter'] = "%{$classFilter}%";
}

if ($statusFilter === 'paid') {
    $whereClauses[] = "(COALESCE(ft.remaining_balance, 0) <= 0 AND COALESCE(ft.total_fees, 0) > 0)";
} elseif ($statusFilter === 'due') {
    $whereClauses[] = "(COALESCE(ft.remaining_balance, 0) > 0)";
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// إجمالي العدد
$countSql = "SELECT COUNT(*) as total FROM students s {$joinSql} {$whereSql}";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) {
    if ($k === ':exact_id') {
        $countStmt->bindValue($k, $v, PDO::PARAM_INT);
    } else {
        $countStmt->bindValue($k, $v, PDO::PARAM_STR);
    }
}
$countStmt->execute();
$totalCount = (int)$countStmt->fetchColumn();

// استعلام الطلاب مع الحسابات المحدثة
$sql = "
    SELECT 
        s.student_id,
        s.student_code,
        s.full_name,
        s.current_class_id,
        " . ($isYearFiltered ? "COALESCE(se.class_name, s.current_class_name)" : "s.current_class_name") . " as current_class_name,
        " . ($isYearFiltered ? "COALESCE(se.branch_name, s.branch_name)" : "s.branch_name") . " as branch_name,
        " . ($isYearFiltered ? ":year_param" : "s.academic_year") . " as academic_year,
        s.gender,
        s.phone1,
        s.mobile,
        s.guardian_name,
        s.status,
        COALESCE(ft.total_fees, 0) as total_fees,
        COALESCE(ft.total_paid, 0) as total_paid,
        COALESCE(ft.remaining_balance, 0) as remaining_balance,
        CASE 
            WHEN COALESCE(ft.total_fees, 0) = 0 THEN 'بدون رسوم'
            WHEN COALESCE(ft.remaining_balance, 0) <= 0 THEN 'خالص الرسوم'
            WHEN COALESCE(ft.total_paid, 0) > 0 THEN 'متبقي عليه'
            ELSE 'لم يسدد'
        END as payment_status,
        ft.last_payment_date
    FROM students s
    {$joinSql}
    {$whereSql}
    ORDER BY s.student_id ASC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    if ($k === ':exact_id') {
        $stmt->bindValue($k, $v, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$students = $stmt->fetchAll();
foreach ($students as &$st) {
    $st['raw_class_name'] = $st['current_class_name'] ?? '';
    $st['current_class_name'] = formatPlatformClassName($st['current_class_name'] ?? '', $st['branch_name'] ?? '');
    $st['class'] = $st['current_class_name'];
}
unset($st);

$classesStmt = $pdo->query("SELECT DISTINCT current_class_name FROM students WHERE current_class_name IS NOT NULL AND current_class_name != '' ORDER BY current_class_id ASC");
$availableClasses = $classesStmt->fetchAll(PDO::FETCH_COLUMN);

// استخراج الأعوام المتاحة
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

jsonResponse(true, [
    "total"           => $totalCount,
    "limit"           => $limit,
    "offset"          => $offset,
    "selected_year"   => $yearFilter,
    "available_years" => $availableYears,
    "classes"         => $availableClasses,
    "students"        => $students
], "تم جلب قائمة الطلاب بنجاح");
