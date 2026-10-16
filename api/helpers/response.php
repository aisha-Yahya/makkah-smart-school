<?php
/**
 * دالة مساعدة لتوحيد هيكل ردود الـ JSON
 */

function jsonResponse($success, $data = null, $message = "", $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        "success"   => $success,
        "message"   => $message,
        "timestamp" => date("c"),
        "data"      => $data
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
