<?php
/**
 * التحقق من رمز المصادقة (Bearer Token / API Key)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/response.php';

function requireApiAuth() {
    $headers = getallheaders();
    $authHeader = "";

    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
    } elseif (isset($headers['authorization'])) {
        $authHeader = $headers['authorization'];
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }

    $token = "";
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
    } elseif (!empty($authHeader)) {
        $token = trim($authHeader);
    }

    // السماح أيضاً بالرمز عبر باراميتر اختياري في الرابط للأغراض الاختبارية
    if (empty($token) && isset($_GET['api_key'])) {
        $token = $_GET['api_key'];
    }

    if (empty($token) || $token !== API_SECRET_KEY) {
        jsonResponse(false, null, "غير مصرح: مفتاح المصادقة غير صالح أو مفقود (Unauthorized).", 401);
    }
}
