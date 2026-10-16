<?php
/**
 * نظام البطاقة المالية للمعلمين والموظفين
 * GET /api/teachers_finance.php
 * GET /api/teachers_finance.php?id=... [أو ?emp_id=...]
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/response.php';

$pdo = getDbConnection();

$empId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['emp_id']) ? (int)$_GET['emp_id'] : null);
$empCode = isset($_GET['code']) ? trim($_GET['code']) : null;
$selectedYear = isset($_GET['year']) ? trim($_GET['year']) : '2026';
$selectedMonth = isset($_GET['month']) ? trim($_GET['month']) : 'all';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : null;
$jobFilter = isset($_GET['job']) ? trim($_GET['job']) : 'all';

// ============================================================
// 1. بطاقة مالية لمعلم / موظف محدد (كشف الحساب التفصيلي)
// ============================================================
if ($empId || $empCode) {
    if ($empId) {
        $empStmt = $pdo->prepare("SELECT * FROM employees WHERE emp_id = ?");
        $empStmt->execute([$empId]);
    } else {
        $cleanCode = ltrim($empCode, '0');
        $paddedCode = str_pad($cleanCode ?: '0', 5, '0', STR_PAD_LEFT);
        $empStmt = $pdo->prepare("
            SELECT * FROM employees 
            WHERE emp_code = ? 
               OR emp_code = ? 
               OR emp_code = ? 
               OR phone1 = ? 
               OR mobile = ?
               OR (emp_id = ? AND ? != '')
            ORDER BY emp_id ASC LIMIT 1
        ");
        $empStmt->execute([$empCode, $cleanCode, $paddedCode, $empCode, $empCode, $cleanCode, $cleanCode]);
    }

    $employee = $empStmt->fetch();
    if (!$employee) {
        jsonResponse(false, null, "لم يتم العثور على أي معلم أو موظف بالكود أو الرقم المدخل ($empCode)", 404);
    }

    $actualEmpId = (int)$employee['emp_id'];

    // بناء شروط الحركات
    $whereParts = ["emp_id = ?"];
    $params = [$actualEmpId];

    if ($selectedYear !== 'all' && !empty($selectedYear)) {
        $whereParts[] = "academic_year LIKE ?";
        $params[] = $selectedYear . '%';
    }

    if ($selectedMonth !== 'all' && is_numeric($selectedMonth)) {
        $whereParts[] = "month_no = ?";
        $params[] = (int)$selectedMonth;
    }

    $whereSql = implode(" AND ", $whereParts);

    // حساب الإجماليات
    $summaryStmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN category = 'استحقاق راتب' THEN amount ELSE 0 END) as total_salary,
            SUM(CASE WHEN category IN ('مكافأة / حافز', 'إضافي', 'استحقاق آخر') THEN amount ELSE 0 END) as total_bonuses,
            SUM(CASE WHEN category = 'سلفة' THEN amount ELSE 0 END) as total_advances,
            SUM(CASE WHEN category IN ('خصم', 'استقطاع آخر') THEN amount ELSE 0 END) as total_deductions,
            SUM(CASE WHEN category = 'صرف راتب نقداً' THEN amount ELSE 0 END) as total_paid_cash,
            SUM(CASE WHEN is_credit = 1 THEN amount ELSE -amount END) as net_payable,
            COUNT(*) as total_trans_count,
            MAX(trans_date) as last_trans_date
        FROM employee_financial_transactions
        WHERE {$whereSql}
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch();

    // جلب السلف بالتفصيل
    $advancesStmt = $pdo->prepare("
        SELECT trans_id, type_name, amount, receipt_no, trans_date, academic_year, month_no, month_name, notes
        FROM employee_financial_transactions
        WHERE {$whereSql} AND category = 'سلفة'
        ORDER BY trans_date DESC, trans_id DESC
    ");
    $advancesStmt->execute($params);
    $advances = $advancesStmt->fetchAll();

    // جلب الخصومات والاستقطاعات بالتفصيل
    $deductionsStmt = $pdo->prepare("
        SELECT trans_id, type_name, amount, receipt_no, trans_date, academic_year, month_no, month_name, notes
        FROM employee_financial_transactions
        WHERE {$whereSql} AND category IN ('خصم', 'استقطاع آخر')
        ORDER BY trans_date DESC, trans_id DESC
    ");
    $deductionsStmt->execute($params);
    $deductions = $deductionsStmt->fetchAll();

    // جلب الرواتب والاستحقاقات المعتمدة
    $salariesStmt = $pdo->prepare("
        SELECT trans_id, type_name, category, amount, receipt_no, trans_date, academic_year, month_no, month_name, notes
        FROM employee_financial_transactions
        WHERE {$whereSql} AND category = 'استحقاق راتب'
        ORDER BY trans_date DESC, trans_id DESC
    ");
    $salariesStmt->execute($params);
    $salaries = $salariesStmt->fetchAll();

    // جلب مسحوبات الراتب المسلمة نقداً
    $cashPaymentsStmt = $pdo->prepare("
        SELECT trans_id, type_name, category, amount, receipt_no, trans_date, academic_year, month_no, month_name, notes
        FROM employee_financial_transactions
        WHERE {$whereSql} AND category = 'صرف راتب نقداً'
        ORDER BY trans_date DESC, trans_id DESC
    ");
    $cashPaymentsStmt->execute($params);
    $cashPayments = $cashPaymentsStmt->fetchAll();

    // كشف الحساب الزمني الكامل
    $allTransStmt = $pdo->prepare("
        SELECT 
            trans_id, type_name, category, is_credit, amount, receipt_no,
            trans_date, academic_year, month_no, month_name, notes
        FROM employee_financial_transactions
        WHERE {$whereSql}
        ORDER BY trans_date DESC, trans_id DESC
    ");
    $allTransStmt->execute($params);
    $allTransactions = $allTransStmt->fetchAll();

    // جلب السنوات الدراسية المقيدة لهذا المعلم
    $yearsStmt = $pdo->prepare("
        SELECT DISTINCT academic_year 
        FROM employee_financial_transactions 
        WHERE emp_id = ? AND academic_year != '' 
        ORDER BY academic_year DESC
    ");
    $yearsStmt->execute([$actualEmpId]);
    $activeYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

    jsonResponse(true, [
        "employee"        => $employee,
        "active_years"    => $activeYears,
        "filter"          => [
            "year"  => $selectedYear,
            "month" => $selectedMonth
        ],
        "summary"         => [
            "total_salary"      => (float)($summary['total_salary'] ?? 0),
            "total_bonuses"     => (float)($summary['total_bonuses'] ?? 0),
            "total_gross"       => (float)(($summary['total_salary'] ?? 0) + ($summary['total_bonuses'] ?? 0)),
            "total_advances"    => (float)($summary['total_advances'] ?? 0),
            "total_deductions"  => (float)($summary['total_deductions'] ?? 0),
            "total_paid_cash"   => (float)($summary['total_paid_cash'] ?? 0),
            "total_cuts"        => (float)(($summary['total_advances'] ?? 0) + ($summary['total_deductions'] ?? 0) + ($summary['total_paid_cash'] ?? 0)),
            "net_payable"       => (float)($summary['net_payable'] ?? 0),
            "trans_count"       => (int)($summary['total_trans_count'] ?? 0),
            "last_trans_date"   => $summary['last_trans_date'] ?? null
        ],
        "advances"        => $advances,
        "deductions"      => $deductions,
        "salaries"        => $salaries,
        "cash_payments"   => $cashPayments,
        "transactions"    => $allTransactions
    ], "تم جلب البطاقة المالية للمعلم بنجاح");
}

// ============================================================
// 2. القائمة العامة لحسابات وسلف المعلمين
// ============================================================

$filterParts = [];
$filterParams = [];

if ($selectedYear !== 'all' && !empty($selectedYear)) {
    $filterParts[] = "t.academic_year LIKE ?";
    $filterParams[] = $selectedYear . '%';
}

if ($selectedMonth !== 'all' && is_numeric($selectedMonth)) {
    $filterParts[] = "t.month_no = ?";
    $filterParams[] = (int)$selectedMonth;
}

if ($jobFilter !== 'all' && !empty($jobFilter)) {
    $filterParts[] = "e.job_title = ?";
    $filterParams[] = $jobFilter;
}

if ($searchQuery) {
    $filterParts[] = "(e.full_name LIKE ? OR e.emp_code LIKE ?)";
    $filterParams[] = "%{$searchQuery}%";
    $filterParams[] = "%{$searchQuery}%";
}

$whereCondition = count($filterParts) > 0 ? "WHERE " . implode(" AND ", $filterParts) : "";

// إحصائيات عامة للمؤشرات العلوية
$globalStatsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT t.emp_id) as active_employees_count,
        SUM(CASE WHEN t.category = 'استحقاق راتب' THEN t.amount ELSE 0 END) as sum_salaries,
        SUM(CASE WHEN t.category IN ('مكافأة / حافز', 'إضافي', 'استحقاق آخر') THEN t.amount ELSE 0 END) as sum_bonuses,
        SUM(CASE WHEN t.category = 'سلفة' THEN t.amount ELSE 0 END) as sum_advances,
        SUM(CASE WHEN t.category IN ('خصم', 'استقطاع آخر') THEN t.amount ELSE 0 END) as sum_deductions,
        SUM(CASE WHEN t.category = 'صرف راتب نقداً' THEN t.amount ELSE 0 END) as sum_paid_cash,
        SUM(CASE WHEN t.is_credit = 1 THEN t.amount ELSE -t.amount END) as sum_net_payable
    FROM employee_financial_transactions t
    JOIN employees e ON t.emp_id = e.emp_id
    {$whereCondition}
");
$globalStatsStmt->execute($filterParams);
$stats = $globalStatsStmt->fetch();

// استعلام قائمة المعلمين مع تفاصيل مبالغهم
$listQuery = "
    SELECT 
        e.emp_id,
        e.emp_code,
        e.full_name,
        e.job_title,
        e.phone1,
        e.mobile,
        e.status,
        SUM(CASE WHEN t.category = 'استحقاق راتب' THEN t.amount ELSE 0 END) as total_salary,
        SUM(CASE WHEN t.category IN ('مكافأة / حافز', 'إضافي', 'استحقاق آخر') THEN t.amount ELSE 0 END) as total_bonuses,
        SUM(CASE WHEN t.category = 'سلفة' THEN t.amount ELSE 0 END) as total_advances,
        SUM(CASE WHEN t.category IN ('خصم', 'استقطاع آخر') THEN t.amount ELSE 0 END) as total_deductions,
        SUM(CASE WHEN t.category = 'صرف راتب نقداً' THEN t.amount ELSE 0 END) as total_paid_cash,
        SUM(CASE WHEN t.is_credit = 1 THEN t.amount ELSE -t.amount END) as net_payable,
        COUNT(t.trans_id) as trans_count,
        MAX(t.trans_date) as last_activity
    FROM employees e
    LEFT JOIN employee_financial_transactions t ON e.emp_id = t.emp_id " . 
    ($selectedYear !== 'all' ? " AND t.academic_year LIKE '{$selectedYear}%'" : "") .
    ($selectedMonth !== 'all' && is_numeric($selectedMonth) ? " AND t.month_no = " . (int)$selectedMonth : "") . "
    " . ($jobFilter !== 'all' ? " WHERE e.job_title = " . $pdo->quote($jobFilter) : "") .
    ($searchQuery ? (strpos($whereCondition, 'WHERE') !== false ? " AND " : " WHERE ") . "(e.full_name LIKE " . $pdo->quote("%{$searchQuery}%") . " OR e.emp_code LIKE " . $pdo->quote("%{$searchQuery}%") . ")" : "") . "
    GROUP BY e.emp_id
    ORDER BY total_advances DESC, total_salary DESC
";

$teachersStmt = $pdo->query($listQuery);
$teachers = $teachersStmt->fetchAll();

// جلب قائمة السنوات وقائمة الوظائف المتاحة للفلاتر
$yearsStmt = $pdo->query("SELECT DISTINCT academic_year FROM employee_financial_transactions ORDER BY academic_year DESC");
$availableYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

$jobsStmt = $pdo->query("SELECT DISTINCT job_title FROM employees WHERE job_title IS NOT NULL AND job_title != '' ORDER BY job_title");
$availableJobs = $jobsStmt->fetchAll(PDO::FETCH_COLUMN);

jsonResponse(true, [
    "overview" => [
        "total_employees"   => (int)($stats['active_employees_count'] ?? count($teachers)),
        "total_salaries"    => (float)($stats['sum_salaries'] ?? 0),
        "total_bonuses"     => (float)($stats['sum_bonuses'] ?? 0),
        "total_advances"    => (float)($stats['sum_advances'] ?? 0),
        "total_deductions"  => (float)($stats['sum_deductions'] ?? 0),
        "net_payable"       => (float)($stats['sum_net_payable'] ?? 0)
    ],
    "filter_options" => [
        "years" => $availableYears,
        "jobs"  => $availableJobs
    ],
    "current_filter" => [
        "year"   => $selectedYear,
        "month"  => $selectedMonth,
        "job"    => $jobFilter,
        "search" => $searchQuery
    ],
    "teachers" => $teachers
], "تم جلب كشوفات حسابات المعلمين بنجاح");
