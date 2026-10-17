<?php
/**
 * Super-fast exporter to prepare Firestore batches & offline complete dataset
 */
ini_set('memory_limit', '1024M');
set_time_limit(300);

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/helpers/class_formatter.php';

$pdo = getDbConnection();
$rootDir = dirname(__DIR__);
$batchDir = $rootDir . '/api/data/firestore_batches';
if (!is_dir($batchDir)) {
    mkdir($batchDir, 0777, true);
}

// Clean previous batches
foreach (glob($batchDir . '/*.json') as $oldBatch) {
    @unlink($oldBatch);
}

// Detect Sync Role (secretariat, finance, or all)
$syncRole = 'all';
$roleFile = $rootDir . '/api/data/sync_role.json';
if (file_exists($roleFile)) {
    $roleCfg = json_decode(file_get_contents($roleFile), true);
    if (!empty($roleCfg['role'])) {
        $syncRole = strtolower(trim($roleCfg['role']));
    }
}
if (!empty($argv)) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--role=') === 0) {
            $syncRole = strtolower(trim(substr($arg, 7)));
        }
    }
}
echo "Active Sync Engine Role: [{$syncRole}]\n";

echo "1. Fetching all students from SQLite...\n";
$students = $pdo->query("SELECT * FROM students ORDER BY student_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$totalStudents = count($students);
echo "Total students: {$totalStudents}\n";

echo "2. Indexing accounts & financials...\n";
$accRows = $pdo->query("SELECT * FROM student_account_summary")->fetchAll(PDO::FETCH_ASSOC);
$accMap = [];
foreach ($accRows as $r) {
    $stId = (int)$r['student_id'];
    $accMap[$stId] = [
        'total_fees'        => (float)($r['total_fees'] ?? 0),
        'total_paid'        => (float)($r['total_paid'] ?? 0),
        'remaining_balance' => (float)($r['remaining_balance'] ?? 0),
        'payment_status'    => (string)($r['payment_status'] ?? 'غير مسدد'),
        'last_payment_date' => (string)($r['last_payment_date'] ?? '')
    ];
}

$transRows = $pdo->query("SELECT student_id, receipt_no, trans_type, fee_type_name, amount, trans_date, notes, academic_year FROM financial_transactions ORDER BY trans_date DESC LIMIT 10000")->fetchAll(PDO::FETCH_ASSOC);
$transMap = [];
foreach ($transRows as $r) {
    $stId = (int)$r['student_id'];
    if (!isset($transMap[$stId])) $transMap[$stId] = [];
    if (count($transMap[$stId]) < 20) { // Keep up to 20 recent transactions
        $transMap[$stId][] = [
            'receipt_no'    => (string)($r['receipt_no'] ?? ''),
            'trans_type'    => (string)($r['trans_type'] ?? ''),
            'fee_type_name' => (string)($r['fee_type_name'] ?? 'رسوم دراسية'),
            'amount'        => (float)($r['amount'] ?? 0),
            'trans_date'    => (string)($r['trans_date'] ?? ''),
            'notes'         => (string)($r['notes'] ?? ''),
            'academic_year' => (string)($r['academic_year'] ?? '')
        ];
    }
}

echo "3. Indexing certificates and annual results...\n";
$certRows = $pdo->query("SELECT * FROM student_certificate_grades ORDER BY sub_order ASC")->fetchAll(PDO::FETCH_ASSOC);
$certMap = [];
foreach ($certRows as $r) {
    $stId = (int)$r['student_id'];
    if (!isset($certMap[$stId])) $certMap[$stId] = [];
    $certMap[$stId][] = [
        'subject_name'   => (string)($r['subject_name'] ?? ''),
        'sub_order'      => (int)($r['sub_order'] ?? 0),
        'sub_deg_max'    => (float)($r['sub_deg_max'] ?? 100),
        'term1_work'     => (float)($r['term1_work'] ?? 0),
        'term1_exam'     => (float)($r['term1_exam'] ?? 0),
        'term2_work'     => (float)($r['term2_work'] ?? 0),
        'term2_exam'     => (float)($r['term2_exam'] ?? 0),
        'final_mark'     => (float)($r['final_mark'] ?? 0),
        'grade_estimate' => (string)($r['grade_estimate'] ?? ''),
        'academic_year'  => (string)($r['academic_year'] ?? '')
    ];
}

$yearResRows = $pdo->query("SELECT * FROM student_year_results ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$yearResMap = [];
foreach ($yearResRows as $r) {
    $stId = (int)$r['student_id'];
    $yearResMap[$stId] = [
        'seat_number'    => (float)($r['seat_number'] ?? 0),
        'final_result'   => (float)($r['final_result'] ?? 0),
        'sub_count'      => (int)($r['sub_count'] ?? 0),
        'ratio'          => (float)($r['ratio'] ?? 0),
        'grade_estimate' => (string)($r['grade_estimate'] ?? 'ممتاز'),
        'status'         => (string)($r['status'] ?? 'ناجح'),
        'academic_year'  => (string)($r['academic_year'] ?? '')
    ];
}

echo "4. Indexing detailed monthly grades & summaries...\n";
$monthSumRows = $pdo->query("SELECT * FROM student_monthly_summaries ORDER BY month_no ASC")->fetchAll(PDO::FETCH_ASSOC);
$monthSumMap = [];
foreach ($monthSumRows as $r) {
    $stId = (int)$r['student_id'];
    $mNo = (int)($r['month_no'] ?? 0);
    if (!isset($monthSumMap[$stId])) $monthSumMap[$stId] = [];
    $monthSumMap[$stId][$mNo] = [
        'month_no'       => $mNo,
        'month_name'     => (string)($r['month_name'] ?? ''),
        'academic_year'  => (string)($r['academic_year'] ?? ''),
        'final_sum'      => (float)($r['final_sum'] ?? 0),
        'sub_count'      => (int)($r['sub_count'] ?? 0),
        'ratio'          => (float)($r['ratio'] ?? 0),
        'percentage'     => (float)($r['ratio'] ?? 0),
        'class_rank'     => (int)($r['class_rank'] ?? 1),
        'rank_text'      => (string)($r['rank_text'] ?? 'الأول'),
        'grade_estimate' => (string)($r['grade_estimate'] ?? 'ممتاز'),
        'status'         => (string)($r['status'] ?? 'ناجح')
    ];
}

$monthlyDetailRows = $pdo->query("SELECT * FROM student_monthly_grades ORDER BY month_no ASC, sub_order ASC")->fetchAll(PDO::FETCH_ASSOC);
$monthlyDetailMap = [];
foreach ($monthlyDetailRows as $r) {
    $stId = (int)$r['student_id'];
    $mNo = (int)($r['month_no'] ?? 0);
    if (!isset($monthlyDetailMap[$stId])) $monthlyDetailMap[$stId] = [];
    if (!isset($monthlyDetailMap[$stId][$mNo])) $monthlyDetailMap[$stId][$mNo] = [];
    $monthlyDetailMap[$stId][$mNo][] = [
        'order'      => count($monthlyDetailMap[$stId][$mNo]) + 1,
        'sub_order'  => (int)($r['sub_order'] ?? 0),
        'name'       => (string)($r['subject_name'] ?? ''),
        'is_counted' => ($r['sub_avreg'] ?? 'نعم') === 'نعم',
        'homework'   => (float)($r['hw_score'] ?? 0),
        'attendance' => (float)($r['att_score'] ?? 0),
        'oral'       => (float)($r['oral_score'] ?? 0),
        'written'    => (float)($r['writ_score'] ?? 0),
        'total'      => (float)($r['score'] ?? 0),
        'max_score'  => 100
    ];
}

echo "5. Assembling full profiles and monthly reports...\n";
$nowIso = date('c');
$offlineDatabase = [];
$firestoreWrites = [];
$batchIndex = 1;
$batchSize = 50; // 50 students per Firestore commit batch

function phpValToFirestore($v) {
    if (is_null($v)) return ["nullValue" => null];
    if (is_bool($v)) return ["booleanValue" => $v];
    if (is_int($v)) return ["integerValue" => (string)$v];
    if (is_float($v)) return ["doubleValue" => $v];
    if (is_string($v)) return ["stringValue" => $v];
    if (is_array($v)) {
        // Check if associative array or sequential array
        $isAssoc = false;
        $i = 0;
        foreach ($v as $k => $item) {
            if ($k !== $i) { $isAssoc = true; break; }
            $i++;
        }
        if ($isAssoc) {
            $fields = [];
            foreach ($v as $k => $item) {
                $fields[(string)$k] = phpValToFirestore($item);
            }
            return ["mapValue" => ["fields" => $fields]];
        } else {
            $values = [];
            foreach ($v as $item) {
                $values[] = phpValToFirestore($item);
            }
            return ["arrayValue" => ["values" => $values]];
        }
    }
    return ["stringValue" => (string)$v];
}

$monthsMeta = [
    7 => "شهر 7 - يوليو",
    8 => "شهر 8 - اغسطس",
    9 => "شهر 9 - سبتمبر"
];

foreach ($students as $st) {
    $stId = (int)$st['student_id'];
    $rawCode = trim($st['student_code'] ?? '');
    $code = !empty($rawCode) ? $rawCode : (string)$stId;
    $rawName = trim($st['full_name'] ?? '');
    $canonicalClass = formatPlatformClassName($st['current_class_name'] ?? '', $st['branch_name'] ?? '');
    $academicYear = !empty($st['academic_year']) ? $st['academic_year'] : '2026-2027 م';
    $seatNo = !empty($st['seat_number']) ? (float)$st['seat_number'] : 0;

    $acc = $accMap[$stId] ?? [
        'total_fees' => 0, 'total_paid' => 0, 'remaining_balance' => 0, 'payment_status' => 'غير مسدد', 'last_payment_date' => ''
    ];
    $trans = $transMap[$stId] ?? [];
    $certs = $certMap[$stId] ?? [];
    $yearRes = $yearResMap[$stId] ?? [
        'seat_number' => $seatNo, 'final_result' => 0, 'sub_count' => 0, 'ratio' => 0, 'grade_estimate' => 'ممتاز', 'status' => 'ناجح'
    ];

    // Build monthly reports
    $stMonthSums = $monthSumMap[$stId] ?? [];
    $stMonthDetails = $monthlyDetailMap[$stId] ?? [];
    $testedMonths = [];
    $monthlyReportsByMonth = [];
    $progression = [];

    foreach ($stMonthSums as $mNo => $mSum) {
        $mName = $mSum['month_name'] ?: ($mNo == 7 ? 'يوليو' : ($mNo == 8 ? 'اغسطس' : 'سبتمبر'));
        $testedMonths[] = [
            'month_no'   => $mNo,
            'month_name' => $mName,
            'label'      => $monthsMeta[$mNo] ?? "شهر {$mNo} - {$mName}"
        ];
        $progression[] = [
            'month_no'   => $mNo,
            'month_name' => $mName,
            'ratio'      => $mSum['ratio']
        ];

        $subs = $stMonthDetails[$mNo] ?? [];
        $monthlyReportsByMonth[(string)$mNo] = [
            'student_id'        => $stId,
            'registration_code' => $code,
            'student_name'      => $rawName,
            'class_name'        => $canonicalClass,
            'academic_year'     => $mSum['academic_year'] ?: $academicYear,
            'month_no'          => $mNo,
            'month_name'        => $mName,
            'subjects'          => $subs,
            'summary'           => [
                'final_sum'      => $mSum['final_sum'],
                'max_sum'        => $mSum['sub_count'] * 100,
                'sub_count'      => $mSum['sub_count'],
                'percentage'     => $mSum['ratio'],
                'grade_estimate' => $mSum['grade_estimate'],
                'status'         => $mSum['status'],
                'class_rank'     => $mSum['class_rank'],
                'rank_text'      => $mSum['rank_text']
            ],
            'progression'       => $progression,
            'attendance'        => ['excused' => 0, 'unexcused' => 0],
            'activities'        => [
                'ratings' => [
                    'الحضور' => 'ممتاز', 'الرياضة' => 'ممتاز', 'الزي' => 'ممتاز', 'الطابور' => 'ممتاز', 'الإذاعة' => 'ممتاز'
                ]
            ]
        ];
    }

    $latestMonthNo = !empty($testedMonths) ? end($testedMonths)['month_no'] : 9;
    $latestReport = $monthlyReportsByMonth[(string)$latestMonthNo] ?? null;

    $profileDoc = [
        'id'                       => $code,
        'code'                     => $code,
        'student_id'               => $stId,
        'name'                     => $rawName,
        'class_name'               => $canonicalClass,
        'raw_class_name'           => (string)($st['current_class_name'] ?? ''),
        'branch'                   => (string)($st['branch_name'] ?? ''),
        'gender'                   => (string)($st['gender'] ?? ''),
        'status'                   => (string)($st['status'] ?? 'مستمر'),
        'phone'                    => (string)($st['mobile'] ?? ($st['phone1'] ?? '')),
        'seat_number'              => $seatNo,
        'academic_year'            => $academicYear,
        'total_fees'               => $acc['total_fees'],
        'total_paid'               => $acc['total_paid'],
        'remaining_balance'        => $acc['remaining_balance'],
        'payment_status'           => $acc['payment_status'],
        'last_payment_date'        => $acc['last_payment_date'],
        'account_summary'          => $acc,
        'transactions'             => $trans,
        'certificate'              => $certs,
        'summary'                  => [
            'total_marks'    => $yearRes['final_result'],
            'max_marks'      => $yearRes['sub_count'] * 100,
            'percentage'     => $yearRes['ratio'] > 0 ? $yearRes['ratio'] : ($latestReport ? $latestReport['summary']['percentage'] : 0),
            'grade_estimate' => $yearRes['grade_estimate'] ?: ($latestReport ? $latestReport['summary']['grade_estimate'] : 'ممتاز'),
            'status'         => $yearRes['status'] ?: ($latestReport ? $latestReport['summary']['status'] : 'ناجح'),
            'seat_number'    => $seatNo,
            'academic_year'  => $academicYear
        ],
        'percentage'               => $yearRes['ratio'] > 0 ? $yearRes['ratio'] : ($latestReport ? $latestReport['summary']['percentage'] : 0),
        'grade_estimate'           => $yearRes['grade_estimate'] ?: ($latestReport ? $latestReport['summary']['grade_estimate'] : 'ممتاز'),
        'exam_result'              => $yearRes['status'] ?: 'ناجح',
        'tested_months'            => $testedMonths,
        'available_years'          => [$academicYear],
        'monthly_report'           => $latestReport,
        'monthly_reports_by_month' => $monthlyReportsByMonth,
        'last_updated'             => $nowIso
    ];

    // Offline DB entries (accessible by name, code, and ID)
    $offlineDatabase[$rawName] = $profileDoc;
    $offlineDatabase[$code] = $profileDoc;
    $offlineDatabase[(string)$stId] = $profileDoc;

    // Firestore batch write
    $firestoreDocPath = "projects/makkah-smart-school/databases/(default)/documents/students/{$code}";
    $fsFields = [];
    foreach ($profileDoc as $k => $v) {
        $fsFields[$k] = phpValToFirestore($v);
    }
    $writeEntry = [
        "update" => [
            "name"   => $firestoreDocPath,
            "fields" => $fsFields
        ]
    ];
    if ($syncRole === 'secretariat') {
        $writeEntry["updateMask"] = [
            "fieldPaths" => [
                "student_id", "registration_code", "student_name", "class_name",
                "seat_number", "national_id", "certificate", "summary", "percentage",
                "grade_estimate", "exam_result", "tested_months", "available_years",
                "monthly_report", "monthly_reports_by_month", "last_updated"
            ]
        ];
    } else if ($syncRole === 'finance') {
        $writeEntry["updateMask"] = [
            "fieldPaths" => [
                "student_id", "registration_code", "total_fees", "total_paid",
                "remaining_balance", "payment_status", "last_payment_date",
                "account_summary", "transactions", "last_updated"
            ]
        ];
    }
    $firestoreWrites[] = $writeEntry;

    // Also write by short ID if different from code
    if ((string)$stId !== $code) {
        $writeEntryShort = [
            "update" => [
                "name"   => "projects/makkah-smart-school/databases/(default)/documents/students/{$stId}",
                "fields" => $fsFields
            ]
        ];
        if (isset($writeEntry["updateMask"])) {
            $writeEntryShort["updateMask"] = $writeEntry["updateMask"];
        }
        $firestoreWrites[] = $writeEntryShort;
    }

    if (count($firestoreWrites) >= $batchSize) {
        $batchPayload = ["writes" => $firestoreWrites];
        $batchFile = sprintf('%s/batch_%03d.json', $batchDir, $batchIndex++);
        file_put_contents($batchFile, json_encode($batchPayload, JSON_UNESCAPED_UNICODE));
        $firestoreWrites = [];
    }
}

if (!empty($firestoreWrites)) {
    $batchPayload = ["writes" => $firestoreWrites];
    $batchFile = sprintf('%s/batch_%03d.json', $batchDir, $batchIndex++);
    file_put_contents($batchFile, json_encode($batchPayload, JSON_UNESCAPED_UNICODE));
}

echo "Generated " . ($batchIndex - 1) . " Firestore batch files.\n";

// Write offline complete JS file
echo "Writing offline_school_pro_data.js...\n";
$offlineJsContent = "window.SCHOOL_PRO_OFFLINE_DB = " . json_encode($offlineDatabase, JSON_UNESCAPED_UNICODE) . ";\n";
file_put_contents($rootDir . '/offline_school_pro_data.js', $offlineJsContent);
echo "offline_school_pro_data.js written successfully (" . round(strlen($offlineJsContent) / 1024 / 1024, 2) . " MB)!\n";

echo "Export completed successfully!\n";
