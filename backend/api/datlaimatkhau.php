<?php
/**
 * ============================================================
 *  API: ĐẶT LẠI MẬT KHẨU (KHI ĐÃ ĐĂNG NHẬP)
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 * ------------------------------------------------------------
 *  Khác với `quenmatkhau.php`:
 *    - quenmatkhau.php     → CHƯA login, cần username/mã NV
 *    - datlaimatkhau.php   → ĐÃ login, chỉ cần MK mới
 *
 *  Bảo mật: verify session trước khi cho đổi.
 * ============================================================
 */

/* ============================================================
 * 1. OUTPUT BUFFERING + HEADER
 * ============================================================ */
if (!ob_get_level()) {
    ob_start();
}

@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');
@header('Cache-Control: no-store, no-cache, must-revalidate');

$__API_DEBUG = defined('IS_DEV_ENV') ? IS_DEV_ENV : false;

/* ============================================================
 * 2. GLOBAL ERROR HANDLER
 * ============================================================ */
set_exception_handler(function (Throwable $ex) use ($__API_DEBUG) {
    if (ob_get_level()) ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $__API_DEBUG
            ? 'Exception: ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine()
            : 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau!',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

register_shutdown_function(function () use ($__API_DEBUG) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_level()) ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $__API_DEBUG
                ? 'Fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']
                : 'Lỗi nghiêm trọng từ server!',
        ], JSON_UNESCAPED_UNICODE);
    }
});

/* ============================================================
 * 3. LOAD CONFIG
 * ============================================================ */
require_once __DIR__ . '/../config/functions.php';

/* ============================================================
 * 4. AUTH GUARD — Bắt buộc đã đăng nhập
 * ============================================================ */
if (!isLoggedIn()) {
    jsonError('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại!', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);
}

/* ============================================================
 * 5. XÁC THỰC CSRF
 * ============================================================ */
verifyCsrfRequest();

/* ============================================================
 * 6. ĐỌC DỮ LIỆU (hỗ trợ cả JSON body và form POST)
 * ============================================================ */
$input = [];

$rawBody     = file_get_contents('php://input');
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== false && !empty($rawBody)) {
    $json = json_decode($rawBody, true);
    if (is_array($json)) {
        $input = $json;
    }
}

if (empty($input)) {
    $input = $_POST;
}

/* ============================================================
 * 7. LẤY USER HIỆN TẠI
 * ============================================================ */
$user   = getCurrentUser();
$userId = (int)($user['id'] ?? 0);

if ($userId <= 0) {
    jsonError('Không xác định được người dùng!', 401);
}

/* ============================================================
 * 8. LẤY DỮ LIỆU MẬT KHẨU MỚI
 * ============================================================ */
$newPw     = (string)($input['new_password']     ?? '');
$confirmPw = (string)($input['confirm_password'] ?? '');

if ($newPw === '' || $confirmPw === '') {
    jsonError('Vui lòng nhập đầy đủ mật khẩu mới!', 422);
}

if ($newPw !== $confirmPw) {
    jsonError('Mật khẩu xác nhận không khớp!', 422);
}

/* ============================================================
 * 9. VALIDATE ĐỘ MẠNH MẬT KHẨU
 * ============================================================ */
$errors = [];

if (strlen($newPw) < 6) {
    $errors[] = 'Mật khẩu phải có tối thiểu 6 ký tự.';
}
if (strlen($newPw) > 255) {
    $errors[] = 'Mật khẩu quá dài (tối đa 255 ký tự).';
}
if (!preg_match('/[A-Z]/', $newPw)) {
    $errors[] = 'Mật khẩu phải có ít nhất 1 chữ in hoa (A-Z).';
}
if (!preg_match('/[a-z]/', $newPw)) {
    $errors[] = 'Mật khẩu phải có ít nhất 1 chữ thường (a-z).';
}
if (!preg_match('/[0-9]/', $newPw)) {
    $errors[] = 'Mật khẩu phải có ít nhất 1 chữ số (0-9).';
}
if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\\\|,.<>\/?`~]/', $newPw)) {
    $errors[] = 'Mật khẩu phải có ít nhất 1 ký tự đặc biệt (!@#$%^&*...).';
}

if (!empty($errors)) {
    jsonError($errors[0], 422, ['errors' => $errors]);
}

/* ============================================================
 * 10. KIỂM TRA USER TỒN TẠI TRONG DB
 * ============================================================ */
try {
    $row = Database::fetchOne(
        'SELECT id, username FROM users WHERE id = ? LIMIT 1',
        [$userId]
    );
} catch (Throwable $e) {
    error_log('[DatLaiMK] DB error: ' . $e->getMessage());
    jsonError('Lỗi kết nối cơ sở dữ liệu!', 500);
}

if (!$row) {
    jsonError('Không tìm thấy tài khoản!', 404);
}

/* ============================================================
 * 11. CẬP NHẬT MẬT KHẨU MỚI
 * ============================================================ */
try {
    /* Chỉ update cột password (không đụng các cột optional) */
    Database::update('users', [
        'password' => password_hash($newPw, PASSWORD_DEFAULT),
    ], 'id = :id', ['id' => $userId]);

    /* Ghi log (best-effort) */
    try {
        Database::insert('login_log', [
            'user_id'    => $userId,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'ket_qua'    => 'reset_password_logged_in',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        /* Bỏ qua nếu bảng login_log chưa có */
    }

    /* Đồng bộ session (không cần thiết nhưng cho chắc) */
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        $_SESSION['user']['password_changed_at'] = time();
    }

    jsonSuccess([
        'username' => $row['username'],
    ], 'Đặt lại mật khẩu thành công!');

} catch (PDOException $ex) {
    error_log('[DatLaiMK] SQL error: ' . $ex->getMessage());
    jsonError(
        $__API_DEBUG
            ? 'SQL Error: ' . $ex->getMessage()
            : 'Không thể đặt lại mật khẩu. Vui lòng thử lại!',
        500
    );
} catch (Throwable $ex) {
    error_log('[DatLaiMK] Error: ' . $ex->getMessage());
    jsonError(
        $__API_DEBUG
            ? 'Error: ' . $ex->getMessage()
            : 'Đã xảy ra lỗi. Vui lòng thử lại!',
        500
    );
}