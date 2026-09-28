<?php
/**
 * ============================================================
 *  API: QUÊN MẬT KHẨU
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 * ------------------------------------------------------------
 *  Routing:
 *    check_username  → Kiểm tra tài khoản
 *    reset_password  → Đặt lại mật khẩu
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

/* Debug tạm bật để thấy lỗi cụ thể. Sau khi fix xong → đổi false */
$__API_DEBUG = true;

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
            : 'Đã xảy ra lỗi hệ thống.',
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
 * 4. CHỈ POST
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);
}

/* ============================================================
 * 5. ĐỌC DỮ LIỆU
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

$action = $input['action'] ?? '';

/* ============================================================
 * 6. CSRF
 * ============================================================ */
$csrfToken = $input['csrf_token']
    ?? $_POST['csrf_token']
    ?? $_SERVER['HTTP_X_CSRF_TOKEN']
    ?? '';

if (!validateCsrfToken($csrfToken)) {
    jsonError('Phiên làm việc đã hết hạn! Vui lòng tải lại trang.', 419);
}

/* ============================================================
 * 7. ĐIỀU HƯỚNG — truyền debug qua tham số
 * ============================================================ */
if ($action === 'check_username') {
    handleCheckUsername($input);
} elseif ($action === 'reset_password') {
    handleResetPassword($input, $__API_DEBUG);
} else {
    jsonError('Hành động không hợp lệ!', 400);
}


/* ============================================================
 * CHECK USERNAME
 * ============================================================ */
function handleCheckUsername(array $input): void
{
    $username = trim((string)($input['username'] ?? ''));

    if ($username === '') {
        jsonError('Vui lòng nhập tên đăng nhập hoặc mã nhân viên!', 422);
    }

    try {
        $user = Database::fetchOne(
            'SELECT u.id, u.username, u.nhan_vien_id,
                    nv.ho_ten, nv.ma_nv, nv.email
             FROM users u
             LEFT JOIN nhan_vien nv ON nv.id = u.nhan_vien_id
             WHERE u.username = :u OR nv.ma_nv = :m
             LIMIT 1',
            ['u' => $username, 'm' => $username]
        );
    } catch (Throwable $e) {
        error_log('[QuenMK] check DB error: ' . $e->getMessage());
        jsonError('Lỗi kết nối CSDL!', 500);
    }

    if (!$user) {
        usleep(300000);
        jsonError('Không tìm thấy tài khoản! Vui lòng kiểm tra lại.', 404);
    }

    $emailMasked = null;
    $hasEmail    = false;

    if (!empty($user['email'])) {
        $emailMasked = maskEmailQuenMK($user['email']);
        $hasEmail    = true;
    }

    jsonSuccess([
        'user' => [
            'id'           => (int)$user['id'],
            'username'     => $user['username'],
            'ho_ten'       => $user['ho_ten'] ?? null,
            'ma_nv'        => $user['ma_nv']  ?? null,
            'has_email'    => $hasEmail,
            'email_masked' => $emailMasked,
        ],
    ], 'Tìm thấy tài khoản!');
}


/* ============================================================
 * RESET PASSWORD
 * ============================================================ */
function handleResetPassword(array $input, bool $debug): void
{
    $userId    = (int)($input['user_id'] ?? 0);
    $newPw     = (string)($input['new_password']     ?? '');
    $confirmPw = (string)($input['confirm_password'] ?? '');

    if ($userId <= 0) {
        jsonError('Thiếu thông tin tài khoản!', 422);
    }
    if ($newPw === '' || $confirmPw === '') {
        jsonError('Vui lòng nhập mật khẩu mới!', 422);
    }
    if ($newPw !== $confirmPw) {
        jsonError('Mật khẩu xác nhận không khớp!', 422);
    }

    /* Validate độ mạnh */
    $errors = [];
    if (strlen($newPw) < 6) {
        $errors[] = 'Mật khẩu phải có tối thiểu 6 ký tự.';
    }
    if (!preg_match('/[A-Z]/', $newPw)) {
        $errors[] = 'Mật khẩu phải có ít nhất 1 chữ in hoa.';
    }
    if (!preg_match('/[a-z]/', $newPw)) {
        $errors[] = 'Mật khẩu phải có ít nhất 1 chữ thường.';
    }
    if (!preg_match('/[0-9]/', $newPw)) {
        $errors[] = 'Mật khẩu phải có ít nhất 1 chữ số.';
    }
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\\\|,.<>\/?`~]/', $newPw)) {
        $errors[] = 'Mật khẩu phải có ít nhất 1 ký tự đặc biệt.';
    }
    if (!empty($errors)) {
        jsonError($errors[0], 422, ['errors' => $errors]);
    }

    /* Kiểm tra user tồn tại */
    try {
        $user = Database::fetchOne(
            'SELECT id FROM users WHERE id = ? LIMIT 1',
            [$userId]
        );
    } catch (Throwable $e) {
        error_log('[QuenMK] check user error: ' . $e->getMessage());
        jsonError('Lỗi kết nối CSDL!', 500);
    }

    if (!$user) {
        jsonError('Không tìm thấy tài khoản!', 404);
    }

    /* ✅ KIỂM TRA SCHEMA — chỉ update cột nào tồn tại */
    $cols = [];
    try {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'users'
        ");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[$row['COLUMN_NAME']] = true;
        }
    } catch (Throwable $e) {
        error_log('[QuenMK] schema error: ' . $e->getMessage());
    }

    /* Build data update — chỉ những cột tồn tại */
    $updateData = [
        'password' => password_hash($newPw, PASSWORD_DEFAULT),
    ];

    if (isset($cols['so_lan_sai'])) {
        $updateData['so_lan_sai'] = 0;
    }
    if (isset($cols['khoa_den'])) {
        $updateData['khoa_den'] = null;
    }

    /* Update */
    try {
        Database::update('users', $updateData, 'id = :id', ['id' => $userId]);
    } catch (PDOException $ex) {
        error_log('[QuenMK] SQL update error: ' . $ex->getMessage());
        jsonError(
            $debug
                ? 'SQL Error: ' . $ex->getMessage()
                : 'Không thể đặt lại mật khẩu. Vui lòng thử lại!',
            500
        );
    } catch (Throwable $ex) {
        error_log('[QuenMK] Update error: ' . $ex->getMessage());
        jsonError(
            $debug
                ? 'Error: ' . $ex->getMessage()
                : 'Đã xảy ra lỗi. Vui lòng thử lại!',
            500
        );
    }

    /* Ghi log (best-effort, không ảnh hưởng kết quả) */
    try {
        Database::insert('login_log', [
            'user_id'    => $userId,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'ket_qua'    => 'reset_password',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        /* Bỏ qua nếu bảng login_log chưa có */
    }

    jsonSuccess([], 'Đặt lại mật khẩu thành công! Bạn có thể đăng nhập bằng mật khẩu mới.');
}


/* ============================================================
 * HELPER: MASK EMAIL
 * ============================================================ */
function maskEmailQuenMK(string $email): string
{
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return $email;
    }

    $name   = $parts[0];
    $domain = $parts[1];

    if (strlen($name) <= 2) {
        $masked = substr($name, 0, 1) . '***';
    } else {
        $masked = substr($name, 0, 1)
                . str_repeat('*', max(1, strlen($name) - 2))
                . substr($name, -1);
    }

    return $masked . '@' . $domain;
}