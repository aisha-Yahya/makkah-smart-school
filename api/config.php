<?php
/**
 * إعدادات واجهة الـ API البرمجية لمنصة مدارس مكة الذكية
 */

// السماح بالوصول عبر المتصفحات وتطبيقات الموبايل (CORS)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

// التعامل مع طلبات Preflight OPTIONS
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// مفتاح المصادقة السري للمزامنة (Bearer Token)
define('API_SECRET_KEY', 'makkah_sync_secret_2027');

// مسارات قواعد البيانات والملفات
define('DATA_DIR', __DIR__ . '/data');
define('SQLITE_DB_PATH', DATA_DIR . '/school_pro_synced.sqlite');
define('SYNC_META_PATH', DATA_DIR . '/sync_meta.json');

// إنشاء مجلد البيانات إذا لم يكن موجوداً
if (!file_exists(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

/**
 * دالة مساعدة لتوحيد النصوص العربية وحذف الفروق بين الهمزات والتاء المربوطة والألف المقصورة
 */
function normalizeArabicText($str) {
    if (!$str) return '';
    $s = trim($str);
    $s = preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $s);
    $s = preg_replace('/[أإآٱ]/u', 'ا', $s);
    $s = preg_replace('/[ؤ]/u', 'و', $s);
    $s = preg_replace('/[ئ]/u', 'ي', $s);
    $s = preg_replace('/[ة]/u', 'ه', $s);
    $s = preg_replace('/[ى]/u', 'ي', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/**
 * الحصول على اتصال PDO بقاعدة بيانات SQLite للمنصة
 */
function getDbConnection() {
    try {
        $pdo = new PDO("sqlite:" . SQLITE_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // تفعيل خيارات الأداء في SQLite
        $pdo->exec("PRAGMA journal_mode = WAL;");
        $pdo->exec("PRAGMA synchronous = NORMAL;");
        $pdo->exec("PRAGMA foreign_keys = ON;");
        
        // تسجيل دالة norm_ar للبحث المرن في النصوص العربية
        $pdo->sqliteCreateFunction('norm_ar', 'normalizeArabicText');
        
        initDatabaseTables($pdo);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "فشل الاتصال بقاعدة بيانات المنصة: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * إنشاء جداول قاعدة البيانات عند أول تشغيل
 */
function initDatabaseTables($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS school_info (
            school_id TEXT PRIMARY KEY,
            school_name TEXT NOT NULL,
            address TEXT,
            phone TEXT,
            city TEXT,
            manager_title TEXT,
            manager_name TEXT,
            email TEXT,
            last_sync_date TEXT
        );

        CREATE TABLE IF NOT EXISTS students (
            student_id INTEGER PRIMARY KEY,
            student_code TEXT NOT NULL UNIQUE,
            full_name TEXT NOT NULL,
            current_class_id INTEGER,
            current_class_name TEXT,
            branch_id INTEGER,
            branch_name TEXT,
            academic_year TEXT,
            gender TEXT,
            birth_date TEXT,
            birth_place TEXT,
            registration_date TEXT,
            phone1 TEXT,
            phone2 TEXT,
            mobile TEXT,
            address TEXT,
            guardian_name TEXT,
            guardian_job TEXT,
            mother_name TEXT,
            previous_school TEXT,
            status TEXT DEFAULT 'مستمر',
            last_updated TEXT
        );

        CREATE TABLE IF NOT EXISTS student_account_summary (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            student_name TEXT NOT NULL,
            class_name TEXT,
            academic_year TEXT,
            total_fees REAL DEFAULT 0,
            total_paid REAL DEFAULT 0,
            remaining_balance REAL DEFAULT 0,
            payment_status TEXT,
            last_payment_date TEXT,
            UNIQUE(student_id, academic_year),
            FOREIGN KEY(student_id) REFERENCES students(student_id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS financial_transactions (
            trans_id INTEGER PRIMARY KEY,
            student_id INTEGER NOT NULL,
            registration_code TEXT NOT NULL,
            trans_type TEXT NOT NULL,
            acc_type_code INTEGER,
            fee_type_id INTEGER,
            fee_type_name TEXT,
            amount REAL NOT NULL,
            receipt_no TEXT,
            trans_date TEXT,
            due_date TEXT,
            academic_year TEXT,
            month_no INTEGER,
            notes TEXT,
            FOREIGN KEY(student_id) REFERENCES students(student_id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS student_grades (
            grade_id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            registration_code TEXT NOT NULL,
            student_name TEXT NOT NULL,
            class_name TEXT,
            subject_id INTEGER,
            subject_name TEXT NOT NULL,
            term_no INTEGER,
            term_name TEXT,
            term_work_score REAL,
            final_exam_score REAL,
            total_score REAL,
            max_score REAL DEFAULT 100,
            grade_estimate TEXT,
            academic_year TEXT,
            FOREIGN KEY(student_id) REFERENCES students(student_id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS fee_installments_plan (
            plan_id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER,
            class_name TEXT,
            fee_type_name TEXT,
            installment_order INTEGER,
            percentage INTEGER,
            start_date TEXT,
            due_date TEXT,
            academic_year TEXT
        );

        CREATE TABLE IF NOT EXISTS school_notifications (
            notification_id INTEGER PRIMARY KEY,
            title TEXT NOT NULL,
            message_text TEXT NOT NULL,
            date_sent TEXT
        );

        CREATE INDEX IF NOT EXISTS idx_students_name ON students(full_name);
        CREATE INDEX IF NOT EXISTS idx_trans_student_id ON financial_transactions(student_id);
        CREATE INDEX IF NOT EXISTS idx_grades_student_id ON student_grades(student_id);
        CREATE INDEX IF NOT EXISTS idx_acc_summary_student ON student_account_summary(student_id);
    ");
}
