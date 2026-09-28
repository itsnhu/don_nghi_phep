<?php
/**
 * ============================================================
 *  API: TRẢ CSRF TOKEN CHO FRONTEND
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 * ============================================================
 */

/* Output buffering sớm */
if (!ob_get_level()) {
    ob_start();
}

/* Headers — chống cache */
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
}

/* Cho phép OPTIONS preflight (nếu frontend khác origin) */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* Chỉ chấp nhận GET */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Phương thức không hợp lệ. Chỉ chấp nhận GET.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* Load config — tự động start session */
require_once __DIR__ . '/../config/functions.php';

/* Sinh token (nếu chưa có) */
$token = generateCsrfToken();

/* Trả JSON */
echo json_encode([
    'success'    => true,
    'csrf_token' => $token,
], JSON_UNESCAPED_UNICODE);
exit;