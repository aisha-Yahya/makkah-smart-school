<?php
/**
 * مشغل المزامنة الفورية اللحظية من داخل المنصة
 * GET /api/trigger_sync.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/response.php';

set_time_limit(300);

$scriptDir = dirname(__DIR__) . '/sync_scripts';
$syncPs1 = $scriptDir . '/sync_school_pro.ps1';

$output = shell_exec("powershell -ExecutionPolicy Bypass -File \"{$syncPs1}\"");
$res = json_decode($output, true);

jsonResponse(true, [
    "synced_at" => date("Y-m-d H:i:s"),
    "details"   => $res
], "تمت المزامنة اللحظية مع سكول برو بنجاح! تم تحديث كافة الشهادات والدرجات وأرقام الجلوس.");
