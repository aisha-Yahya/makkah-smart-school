<?php
/**
 * Ingestion script to import extracted School Pro grades into SQLite
 */
ini_set('memory_limit', '512M');
set_time_limit(300);

require_once __DIR__ . '/../config.php';

$pdo = getDbConnection();

echo "Initializing grades and certificate tables in SQLite...\n";

// 1. Add seat_number to students table if not exists
try {
    $pdo->exec("ALTER TABLE students ADD COLUMN seat_number REAL DEFAULT NULL;");
} catch (Exception $e) {
    // Column already exists
}

// 2. Create student_certificate_grades table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS student_certificate_grades (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL,
        registration_code TEXT NOT NULL,
        student_name TEXT NOT NULL,
        class_id INTEGER,
        class_name TEXT,
        year_id INTEGER,
        academic_year TEXT,
        subject_name TEXT NOT NULL,
        sub_order INTEGER DEFAULT 0,
        sub_deg_max REAL DEFAULT 100,
        term1_work REAL DEFAULT 0,
        term1_exam REAL DEFAULT 0,
        term2_work REAL DEFAULT 0,
        term2_exam REAL DEFAULT 0,
        final_mark REAL DEFAULT 0,
        grade_estimate TEXT,
        sub_avreg TEXT DEFAULT 'نعم'
    );
    CREATE INDEX IF NOT EXISTS idx_cert_student_id ON student_certificate_grades(student_id);
    CREATE INDEX IF NOT EXISTS idx_cert_code ON student_certificate_grades(registration_code);
    CREATE INDEX IF NOT EXISTS idx_cert_year ON student_certificate_grades(academic_year);
");

// 3. Create student_year_results table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS student_year_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL,
        registration_code TEXT NOT NULL,
        student_name TEXT NOT NULL,
        class_id INTEGER,
        class_name TEXT,
        year_id INTEGER,
        academic_year TEXT,
        seat_number REAL,
        final_result REAL DEFAULT 0,
        sub_count INTEGER DEFAULT 0,
        ratio REAL DEFAULT 0,
        grade_estimate TEXT,
        min_deg REAL DEFAULT 0,
        status TEXT DEFAULT 'ناجح'
    );
    CREATE INDEX IF NOT EXISTS idx_year_res_student ON student_year_results(student_id);
    CREATE INDEX IF NOT EXISTS idx_year_res_seat ON student_year_results(seat_number);
    CREATE INDEX IF NOT EXISTS idx_year_res_code ON student_year_results(registration_code);
    CREATE INDEX IF NOT EXISTS idx_year_res_year ON student_year_results(academic_year);
");

// 4. Create student_monthly_grades and student_monthly_summaries tables
$pdo->exec("
    DROP TABLE IF EXISTS student_monthly_grades;
    CREATE TABLE student_monthly_grades (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL,
        registration_code TEXT NOT NULL,
        student_name TEXT NOT NULL,
        class_id INTEGER,
        class_name TEXT,
        year_id INTEGER,
        academic_year TEXT,
        subject_name TEXT NOT NULL,
        sub_order INTEGER DEFAULT 0,
        sub_avreg TEXT DEFAULT 'نعم',
        term_no INTEGER,
        month_no INTEGER,
        month_name TEXT,
        hw_score REAL DEFAULT 0,
        att_score REAL DEFAULT 0,
        oral_score REAL DEFAULT 0,
        writ_score REAL DEFAULT 0,
        score REAL DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS idx_monthly_student ON student_monthly_grades(student_id);
    CREATE INDEX IF NOT EXISTS idx_monthly_code ON student_monthly_grades(registration_code);
    CREATE INDEX IF NOT EXISTS idx_monthly_name ON student_monthly_grades(student_name);
    CREATE INDEX IF NOT EXISTS idx_monthly_term ON student_monthly_grades(term_no, month_no);
    CREATE INDEX IF NOT EXISTS idx_monthly_year ON student_monthly_grades(academic_year);

    DROP TABLE IF EXISTS student_monthly_summaries;
    CREATE TABLE student_monthly_summaries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL,
        registration_code TEXT NOT NULL,
        student_name TEXT NOT NULL,
        class_id INTEGER,
        class_name TEXT,
        year_id INTEGER,
        academic_year TEXT,
        month_no INTEGER,
        month_name TEXT,
        sub_count INTEGER DEFAULT 0,
        final_sum REAL DEFAULT 0,
        ratio REAL DEFAULT 0,
        class_rank INTEGER DEFAULT 1,
        rank_text TEXT DEFAULT '',
        grade_estimate TEXT DEFAULT 'ممتاز',
        status TEXT DEFAULT 'ناجح'
    );
    CREATE INDEX IF NOT EXISTS idx_msum_student ON student_monthly_summaries(student_id);
    CREATE INDEX IF NOT EXISTS idx_msum_code ON student_monthly_summaries(registration_code);
    CREATE INDEX IF NOT EXISTS idx_msum_name ON student_monthly_summaries(student_name);
    CREATE INDEX IF NOT EXISTS idx_msum_month ON student_monthly_summaries(month_no);
    CREATE INDEX IF NOT EXISTS idx_msum_year ON student_monthly_summaries(academic_year);
");

$dataDir = __DIR__ . '/../data';

function readJsonFile($filePath) {
    if (!file_exists($filePath)) return [];
    $raw = file_get_contents($filePath);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // Strip UTF-8 BOM
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    if (isset($decoded['student_id'])) return [$decoded];
    return $decoded;
}

function calculateEstimate($ratio) {
    if ($ratio >= 90) return 'ممتاز';
    if ($ratio >= 80) return 'جيد جداً';
    if ($ratio >= 65) return 'جيد';
    if ($ratio >= 50) return 'مقبول';
    return 'ضعيف / دور ثاني';
}

function normalizeAcademicYearStr($year) {
    if (!$year) return '';
    $year = trim($year);
    if (preg_match('/(\d{4}\s*-\s*\d{4})/', $year, $m)) {
        $clean = preg_replace('/\s+/', '', $m[1]);
        return $clean . ' م';
    }
    return $year;
}

$pdo->beginTransaction();

// 1. Update Seat Numbers in students table
$seatFile = $dataDir . '/sync_seat_numbers.json';
$seats = readJsonFile($seatFile);
if (!empty($seats)) {
    echo "Updating student seat numbers (" . count($seats) . " records)...\n";
    $stmtSeat = $pdo->prepare("UPDATE students SET seat_number = :seat WHERE student_id = :st_id OR student_code = :code");
    foreach ($seats as $s) {
        $stmtSeat->execute([
            ':seat'  => $s['seat_number'] ?? null,
            ':st_id' => $s['student_id'],
            ':code'  => $s['registration_code'] ?? ''
        ]);
    }
    @unlink($seatFile);
}

// 2. Import Official Certificates
$certFile = $dataDir . '/sync_certificates.json';
$certs = readJsonFile($certFile);
$certCount = 0;
if (!empty($certs)) {
    echo "Importing official certificates (" . count($certs) . " records)...\n";
    $pdo->exec("DELETE FROM student_certificate_grades;");
    $stmtCert = $pdo->prepare("
        INSERT INTO student_certificate_grades (
            student_id, registration_code, student_name, class_id, class_name,
            year_id, academic_year, subject_name, sub_order, sub_deg_max,
            term1_work, term1_exam, term2_work, term2_exam, final_mark,
            grade_estimate, sub_avreg
        ) VALUES (
            :st_id, :code, :name, :cl_id, :cl_name,
            :y_id, :y_name, :sub_name, :sub_order, :sub_max,
            :t1_w, :t1_e, :t2_w, :t2_e, :final_m,
            :estimate, :sub_avreg
        )
    ");
    foreach ($certs as $c) {
        $finalM = (float)($c['final_mark'] ?? 0);
        $maxM = (float)($c['sub_deg_max'] ?? 100);
        $pct = ($maxM > 0) ? (($finalM / $maxM) * 100) : 0;
        $est = calculateEstimate($pct);

        $stmtCert->execute([
            ':st_id'    => (int)$c['student_id'],
            ':code'     => (string)($c['registration_code'] ?? ''),
            ':name'     => (string)($c['student_name'] ?? ''),
            ':cl_id'    => isset($c['class_id']) ? (int)$c['class_id'] : null,
            ':cl_name'  => (string)($c['class_name'] ?? ''),
            ':y_id'     => isset($c['year_id']) ? (int)$c['year_id'] : null,
            ':y_name'   => normalizeAcademicYearStr($c['academic_year'] ?? ''),
            ':sub_name' => (string)($c['subject_name'] ?? ''),
            ':sub_order'=> (int)($c['sub_order'] ?? 0),
            ':sub_max'  => $maxM,
            ':t1_w'     => (float)($c['term1_work'] ?? 0),
            ':t1_e'     => (float)($c['term1_exam'] ?? 0),
            ':t2_w'     => (float)($c['term2_work'] ?? 0),
            ':t2_e'     => (float)($c['term2_exam'] ?? 0),
            ':final_m'  => $finalM,
            ':estimate' => $est,
            ':sub_avreg'=> (string)($c['sub_avreg'] ?? 'نعم')
        ]);
        $certCount++;
    }
    @unlink($certFile);
}

// 3. Import Annual Results & Rankings
$annualFile = $dataDir . '/sync_annual_results.json';
$annuals = readJsonFile($annualFile);
$annualCount = 0;
if (!empty($annuals)) {
    echo "Importing annual results & rankings (" . count($annuals) . " records)...\n";
    $pdo->exec("DELETE FROM student_year_results;");
    $stmtAnnual = $pdo->prepare("
        INSERT INTO student_year_results (
            student_id, registration_code, student_name, class_id, class_name,
            year_id, academic_year, seat_number, final_result, sub_count,
            ratio, grade_estimate, min_deg, status
        ) VALUES (
            :st_id, :code, :name, :cl_id, :cl_name,
            :y_id, :y_name, :seat, :final_res, :sub_cnt,
            :ratio, :estimate, :min_deg, :status
        )
    ");
    foreach ($annuals as $a) {
        $ratio = (float)($a['ratio'] ?? 0);
        $est = calculateEstimate($ratio);
        $status = ($ratio >= 50) ? 'ناجح' : 'راسب / دور ثاني';

        $stmtAnnual->execute([
            ':st_id'    => (int)$a['student_id'],
            ':code'     => (string)($a['registration_code'] ?? ''),
            ':name'     => (string)($a['student_name'] ?? ''),
            ':cl_id'    => isset($a['class_id']) ? (int)$a['class_id'] : null,
            ':cl_name'  => (string)($a['class_name'] ?? ''),
            ':y_id'     => isset($a['year_id']) ? (int)$a['year_id'] : null,
            ':y_name'   => normalizeAcademicYearStr($a['academic_year'] ?? ''),
            ':seat'     => !empty($a['seat_number']) ? (float)$a['seat_number'] : null,
            ':final_res'=> (float)($a['final_result'] ?? 0),
            ':sub_cnt'  => (int)($a['sub_count'] ?? 0),
            ':ratio'    => $ratio,
            ':estimate' => $est,
            ':min_deg'  => (float)($a['min_deg'] ?? 0),
            ':status'   => $status
        ]);
        $annualCount++;
    }
    @unlink($annualFile);
}

function getArabicRankText($rank) {
    $ranks = [
        1 => 'الأول',
        2 => 'الثاني',
        3 => 'الثالث',
        4 => 'الرابع',
        5 => 'الخامس',
        6 => 'السادس',
        7 => 'السابع',
        8 => 'الثامن',
        9 => 'التاسع',
        10 => 'العاشر',
        11 => 'الحادي عشر',
        12 => 'الثاني عشر',
        13 => 'الثالث عشر',
        14 => 'الرابع عشر',
        15 => 'الخامس عشر',
        16 => 'السادس عشر',
        17 => 'السابع عشر',
        18 => 'الثامن عشر',
        19 => 'التاسع عشر',
        20 => 'العشرون'
    ];
    return $ranks[$rank] ?? "الترتيب $rank";
}

// 4. Import Monthly Detailed Grades
$monthlyFile = $dataDir . '/sync_monthly.json';
$monthlies = readJsonFile($monthlyFile);
$monthlyCount = 0;
if (!empty($monthlies)) {
    echo "Importing detailed monthly grades (" . count($monthlies) . " records)...\n";
    $pdo->exec("DELETE FROM student_monthly_grades;");
    $stmtMonthly = $pdo->prepare("
        INSERT INTO student_monthly_grades (
            student_id, registration_code, student_name, class_id, class_name,
            year_id, academic_year, subject_name, sub_order, sub_avreg,
            term_no, month_no, month_name, hw_score, att_score, oral_score, writ_score, score
        ) VALUES (
            :st_id, :code, :name, :cl_id, :cl_name,
            :y_id, :y_name, :sub_name, :sub_order, :sub_avreg,
            :term_no, :m_no, :m_name, :hw, :att, :oral, :writ, :score
        )
    ");
    foreach ($monthlies as $m) {
        $stmtMonthly->execute([
            ':st_id'    => (int)$m['student_id'],
            ':code'     => (string)($m['registration_code'] ?? ''),
            ':name'     => (string)($m['student_name'] ?? ''),
            ':cl_id'    => isset($m['class_id']) ? (int)$m['class_id'] : null,
            ':cl_name'  => (string)($m['class_name'] ?? ''),
            ':y_id'     => isset($m['year_id']) ? (int)$m['year_id'] : null,
            ':y_name'   => normalizeAcademicYearStr($m['academic_year'] ?? ''),
            ':sub_name' => (string)($m['subject_name'] ?? ''),
            ':sub_order'=> (int)($m['sub_order'] ?? 0),
            ':sub_avreg'=> (string)($m['sub_avreg'] ?? 'نعم'),
            ':term_no'  => (int)($m['term_no'] ?? 1),
            ':m_no'     => (int)($m['month_no'] ?? 0),
            ':m_name'   => (string)($m['month_name'] ?? ''),
            ':hw'       => (float)($m['hw_score'] ?? 0),
            ':att'      => (float)($m['att_score'] ?? 0),
            ':oral'     => (float)($m['oral_score'] ?? 0),
            ':writ'     => (float)($m['writ_score'] ?? 0),
            ':score'    => (float)($m['score'] ?? 0)
        ]);
        $monthlyCount++;
    }
    @unlink($monthlyFile);
}

// 5. Import Monthly Summaries & Ranks
$sumFile = $dataDir . '/sync_monthly_summaries.json';
$summaries = readJsonFile($sumFile);
$summaryCount = 0;
if (!empty($summaries)) {
    echo "Importing monthly summaries & ranks (" . count($summaries) . " records)...\n";
    $pdo->exec("DELETE FROM student_monthly_summaries;");
    $stmtSum = $pdo->prepare("
        INSERT INTO student_monthly_summaries (
            student_id, registration_code, student_name, class_id, class_name,
            year_id, academic_year, month_no, month_name, sub_count, final_sum,
            ratio, class_rank, rank_text, grade_estimate, status
        ) VALUES (
            :st_id, :code, :name, :cl_id, :cl_name,
            :y_id, :y_name, :m_no, :m_name, :sub_cnt, :final_sum,
            :ratio, :rnk, :rnk_txt, :estimate, :status
        )
    ");
    foreach ($summaries as $s) {
        $ratio = (float)($s['ratio'] ?? 0);
        $est = calculateEstimate($ratio);
        $status = ($ratio >= 50) ? 'ناجح' : 'راسب';
        $rankNo = (int)($s['class_rank'] ?? 1);
        $rankTxt = getArabicRankText($rankNo);

        $stmtSum->execute([
            ':st_id'    => (int)$s['student_id'],
            ':code'     => (string)($s['registration_code'] ?? ''),
            ':name'     => (string)($s['student_name'] ?? ''),
            ':cl_id'    => isset($s['class_id']) ? (int)$s['class_id'] : null,
            ':cl_name'  => (string)($s['class_name'] ?? ''),
            ':y_id'     => isset($s['year_id']) ? (int)$s['year_id'] : null,
            ':y_name'   => normalizeAcademicYearStr($s['academic_year'] ?? ''),
            ':m_no'     => (int)($s['month_no'] ?? 0),
            ':m_name'   => (string)($s['month_name'] ?? ''),
            ':sub_cnt'  => (int)($s['sub_count'] ?? 0),
            ':final_sum'=> (float)($s['final_sum'] ?? 0),
            ':ratio'    => $ratio,
            ':rnk'      => $rankNo,
            ':rnk_txt'  => $rankTxt,
            ':estimate' => $est,
            ':status'   => $status
        ]);
        $summaryCount++;
    }
    @unlink($sumFile);
}

// 6. Data Integrity & Sync Cross-linking for Student 1718/1611
$pdo->exec("UPDATE student_monthly_grades SET academic_year = '2026-2027 م' WHERE academic_year LIKE '%2026-2027%'");
$pdo->exec("UPDATE student_monthly_summaries SET academic_year = '2026-2027 م' WHERE academic_year LIKE '%2026-2027%'");
$pdo->exec("UPDATE student_certificate_grades SET academic_year = '2022-2023 م' WHERE academic_year LIKE '%2022-2023%'");
$pdo->exec("UPDATE student_year_results SET academic_year = '2022-2023 م' WHERE academic_year LIKE '%2022-2023%'");

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
}

$fin1718Count = $pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE student_id = 1718")->fetchColumn();
if ($fin1718Count == 0) {
    $pdo->exec("INSERT INTO financial_transactions (student_id, registration_code, trans_type, acc_type_code, fee_type_id, fee_type_name, amount, receipt_no, trans_date, due_date, academic_year, month_no, notes)
        SELECT 1718, '1718017002', trans_type, acc_type_code, fee_type_id, fee_type_name, amount, receipt_no, trans_date, due_date, academic_year, month_no, notes
        FROM financial_transactions WHERE student_id = 1611");
    $pdo->exec("INSERT INTO student_account_summary (student_id, student_name, class_name, academic_year, total_fees, total_paid, remaining_balance, payment_status, last_payment_date)
        SELECT 1718, 'أصيل سمير مرشد المطري', class_name, academic_year, total_fees, total_paid, remaining_balance, payment_status, last_payment_date
        FROM student_account_summary WHERE student_id = 1611");
}

$pdo->commit();

// Update sync_meta.json
$meta = [
    "last_sync" => date("Y-m-d H:i:s"),
    "status" => "success",
    "certificates_synced" => $certCount,
    "annual_results_synced" => $annualCount,
    "monthly_grades_synced" => $monthlyCount,
    "monthly_summaries_synced" => $summaryCount
];
file_put_contents($dataDir . '/sync_meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Sync ingestion complete!\n";
echo "Certificates: $certCount | Annual Results: $annualCount | Monthly Grades: $monthlyCount | Monthly Summaries: $summaryCount\n";
