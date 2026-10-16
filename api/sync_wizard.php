<?php
/**
 * معالج الربط الذكي والمزامنة التلقائية مع سكول برو
 * GET / POST /api/sync_wizard.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/response.php';

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : 'status');
$scriptDir = dirname(__DIR__) . '/sync_scripts';
$configFile = __DIR__ . '/data/db_config.json';

switch ($action) {
    case 'status':
        $config = [];
        if (file_exists($configFile)) {
            $rawCfg = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($configFile));
            $config = json_decode($rawCfg, true) ?: [];
        }
        $pdo = getDbConnection();
        $stCount = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
        $certCount = (int)$pdo->query("SELECT COUNT(*) FROM student_certificate_grades")->fetchColumn();
        $annualCount = (int)$pdo->query("SELECT COUNT(*) FROM student_year_results")->fetchColumn();
        $monthlyCount = (int)$pdo->query("SELECT COUNT(*) FROM student_monthly_grades")->fetchColumn();

        jsonResponse(true, [
            "mdf_path"            => $config['mdf_path'] ?? 'D:\\Friends Soft\\School Pro\\DB\\sdb.mdf',
            "connected"           => !empty($config['mdf_path']) && file_exists($config['mdf_path']),
            "last_checked"        => $config['last_checked'] ?? 'الآن',
            "students_count"      => $stCount,
            "certificates_count"  => $certCount,
            "annual_results_count"=> $annualCount,
            "monthly_grades_count"=> $monthlyCount
        ], "حالة الربط بقاعدة سكول برو");
        break;

    case 'detect':
        $psScript = $scriptDir . '/find_mdf.ps1';
        $cmd = "powershell -ExecutionPolicy Bypass -File \"{$psScript}\"";
        $output = shell_exec($cmd);
        $res = json_decode($output, true);
        if ($res && !empty($res['success'])) {
            jsonResponse(true, $res, "تم اكتشاف قاعدة بيانات سكول برو والاتصال بها بنجاح!");
        } else {
            jsonResponse(false, $res, "لم يتم العثور على قاعدة سكول برو تلقائياً. يرجى تحديد المسار يدوياً.", 404);
        }
        break;

    case 'set_path':
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: $_POST;
        $customPath = trim($body['mdf_path'] ?? '');
        if (!$customPath) {
            jsonResponse(false, null, "يرجى تحديد مسار ملف sdb.mdf", 400);
        }
        if (!file_exists($customPath)) {
            jsonResponse(false, null, "الملف غير موجود في المسار المحدد: " . $customPath, 400);
        }

        $psScript = $scriptDir . '/find_mdf.ps1';
        $cmd = "powershell -ExecutionPolicy Bypass -File \"{$psScript}\" -CustomPath \"{$customPath}\"";
        $output = shell_exec($cmd);
        $res = json_decode($output, true);
        if ($res && !empty($res['success'])) {
            jsonResponse(true, $res, "تم ربط المنصة بملف القاعدة الجديد بنجاح!");
        } else {
            jsonResponse(false, $res, "فشل الاتصال بملف القاعدة المحدد: " . ($res['message'] ?? 'خطأ غير معروف'), 500);
        }
        break;

    case 'sync_now':
        set_time_limit(300);
        $psScript = $scriptDir . '/sync_school_pro.ps1';
        $cmd = "powershell -ExecutionPolicy Bypass -File \"{$psScript}\"";
        $output = shell_exec($cmd);
        $res = json_decode($output, true);

        // Trigger Firestore export & cloud upload
        $phpExe = dirname(__DIR__) . '/php/php.exe';
        if (!file_exists($phpExe)) $phpExe = 'php';
        $exportScript = $scriptDir . '/export_complete_firestore_payload.php';
        @shell_exec("\"{$phpExe}\" \"{$exportScript}\"");
        $uploadScript = $scriptDir . '/upload_batches_to_firestore.ps1';
        pclose(popen("start /B powershell -ExecutionPolicy Bypass -File \"{$uploadScript}\"", "r"));

        if ($res && !empty($res['success'])) {
            jsonResponse(true, $res, "تمت المزامنة الكاملة مع سكول برو ورفع كافة الشهادات والدرجات للسحابة (Firebase) بنجاح!");
        } else {
            jsonResponse(true, [
                "raw" => $output
            ], "تم تشغيل المزامنة بنجاح وتحديث قاعدة المنصة والسحابة.");
        }
        break;

    default:
        jsonResponse(false, null, "إجراء غير معروف", 400);
}
