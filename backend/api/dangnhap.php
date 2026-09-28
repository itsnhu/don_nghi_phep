<?php
/**
 * ============================================================
 *  API: ĐĂNG NHẬP + ĐĂNG XUẤT + CHECK SESSION
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 * ------------------------------------------------------------
 *  Routing:
 *    POST                    → Đăng nhập
 *    POST/GET action=logout  → Đăng xuất
 *    GET  ?action=check      → Check session
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

/* BẬT DEBUG TẠM để thấy lỗi thật — fix xong đổi thành false */
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
            : 'Đã xảy ra lỗi hệ thống!',
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
 * 4. ĐIỀU HƯỚNG
 * ============================================================ */
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === '') {
    $action = 'login';
}

switch ($action) {
    case 'login':
        handleLoginAPI($__API_DEBUG);
        break;
    case 'logout':
        handleLogoutAPI();
        break;
    case 'check':
        handleCheckSessionAPI();
        break;
    default:
        jsonError('Hành động không hợp lệ!', 400);
}


/* ============================================================
 * LOGIN
 * ============================================================ */
function handleLoginAPI(bool $debug): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Phương thức không hợp lệ. Vui lòng dùng POST!', 405);
    }

    /* Đã đăng nhập → trả redirect luôn */
    if (isLoggedIn()) {
        $currentUser = getCurrentUser();
        jsonSuccess([
            'redirect_url'      => getRedirectUrlByRole($currentUser['vai_tro'] ?? ''),
            'already_logged_in' => true,
        ], 'Bạn đã đăng nhập.');
    }

    /* CSRF */
    verifyCsrfRequest();

    /* Input */
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '') {
        jsonError('Vui lòng nhập tên đăng nhập!', 422);
    }
    if ($password === '') {
        jsonError('Vui lòng nhập mật khẩu!', 422);
    }

    /* ✅ KIỂM TRA SCHEMA BẢNG users — chỉ select cột nào tồn tại */
    $db = Database::getConnection();
    $cols = [];
    try {
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
        error_log('[Login] schema check: ' . $e->getMessage());
    }

    /* Build SELECT động — chỉ cột nào có */
    $selectCols = ['u.id', 'u.username', 'u.password'];
    foreach (['vai_tro', 'nhan_vien_id', 'trang_thai', 'so_lan_sai', 'khoa_den'] as $c) {
        if (isset($cols[$c])) {
            $selectCols[] = "u.{$c}";
        }
    }

    /* Join nhan_vien nếu có cột nhan_vien_id */
    $joinSql   = '';
    $extraCols = [];
    if (isset($cols['nhan_vien_id'])) {
        $joinSql = 'LEFT JOIN nhan_vien nv ON nv.id = u.nhan_vien_id';
        $extraCols[] = 'nv.ho_ten';
        $extraCols[] = 'nv.ma_nv';
        $extraCols[] = 'nv.email';
    }

    $selectList = implode(', ', array_merge($selectCols, $extraCols));

    /* Build WHERE cho username HOẶC mã NV */
    $whereSql = 'u.username = :u';
    $params   = ['u' => $username];

    if (isset($cols['nhan_vien_id'])) {
        $whereSql = '(u.username = :u OR nv.ma_nv = :m)';
        $params   = ['u' => $username, 'm' => $username];
    }

    /* Query user */
    $user = null;
    try {
        $sql = "SELECT {$selectList}
                FROM users u
                {$joinSql}
                WHERE {$whereSql}
                LIMIT 1";
        $user = Database::fetchOne($sql, $params);
    } catch (Throwable $e) {
        error_log('[Login] query error: ' . $e->getMessage());
        jsonError(
            $debug
                ? 'SQL query error: ' . $e->getMessage()
                : 'Lỗi hệ thống. Vui lòng thử lại sau!',
            500
        );
    }

    if ($user === null) {
        usleep(300000);
        jsonError('Tài khoản hoặc mật khẩu không chính xác!', 401);
    }

    /* Kiểm tra khóa tạm (nếu có cột) */
    if (isset($cols['khoa_den']) && !empty($user['khoa_den'])) {
        $unlockAt = strtotime($user['khoa_den']);
        if ($unlockAt > time()) {
            $remain = (int)ceil(($unlockAt - time()) / 60);
            jsonError("Tài khoản đang bị tạm khóa. Vui lòng thử lại sau {$remain} phút.", 423);
        }
    }

    /* Kiểm tra trạng thái (nếu có cột) */
    if (isset($cols['trang_thai']) && isset($user['trang_thai'])) {
        $tt = $user['trang_thai'];
        if ($tt !== 'active' && $tt !== '1' && $tt !== 1) {
            jsonError('Tài khoản đã bị vô hiệu hóa!', 403);
        }
    }

    /* Verify password */
    $hash = (string)($user['password'] ?? '');
    $ok   = false;

    if ($hash !== '') {
        if (password_verify($password, $hash)) {
            $ok = true;
        } elseif (strlen($hash) === 32 && hash_equals($hash, md5($password))) {
            /* Fallback md5 cũ → tự nâng cấp */
            $ok = true;
            try {
                Database::update('users',
                    ['password' => password_hash($password, PASSWORD_DEFAULT)],
                    'id = :id',
                    ['id' => $user['id']]
                );
            } catch (Throwable $e) {
                error_log('[Login] upgrade hash: ' . $e->getMessage());
            }
        }
    }

    if (!$ok) {
        registerFailedAttempt($user, $cols);
        jsonError('Tài khoản hoặc mật khẩu không chính xác!', 401);
    }

    /* Login thành công — regenerate session */
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['user'] = [
        'id'           => (int)$user['id'],
        'username'     => $user['username'],
        'vai_tro'      => $user['vai_tro']      ?? 'nhan_vien',
        'nhan_vien_id' => !empty($user['nhan_vien_id']) ? (int)$user['nhan_vien_id'] : null,
        'ho_ten'       => $user['ho_ten']       ?? null,
        'ma_nv'        => $user['ma_nv']        ?? null,
        'email'        => $user['email']        ?? null,
        'login_at'     => time(),
        'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    /* Reset đếm sai + update last_login (chỉ cột nào có) */
    try {
        $updateData = [];
        if (isset($cols['so_lan_sai'])) {
            $updateData['so_lan_sai'] = 0;
        }
        if (isset($cols['khoa_den'])) {
            $updateData['khoa_den'] = null;
        }
        if (isset($cols['last_login'])) {
            $updateData['last_login'] = date('Y-m-d H:i:s');
        }
        if (isset($cols['last_ip'])) {
            $updateData['last_ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
        }

        if (!empty($updateData)) {
            Database::update('users', $updateData, 'id = :id', ['id' => $user['id']]);
        }
    } catch (Throwable $e) {
        error_log('[Login] update last_login: ' . $e->getMessage());
    }

    jsonSuccess([
        'redirect_url' => getRedirectUrlByRole($user['vai_tro'] ?? ''),
        'user' => [
            'id'       => (int)$user['id'],
            'username' => $user['username'],
            'ho_ten'   => $user['ho_ten']  ?? null,
            'vai_tro'  => $user['vai_tro'] ?? 'nhan_vien',
        ],
    ], 'Đăng nhập thành công!');
}


/* ============================================================
 * LOGOUT
 * ============================================================ */
function handleLogoutAPI(): void
{
    $loginUrl = BASE_URL . '/frontend/dangnhap_quenmk/dangnhap.html';

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'] ?: '/',
                'domain'   => $params['domain'] ?: '',
                'secure'   => $params['secure'] ?? false,
                'httponly' => $params['httponly'] ?? true,
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

    if ($isAjax) {
        jsonSuccess(['redirect_url' => $loginUrl], 'Bạn đã đăng xuất!');
    }

    header('Location: ' . $loginUrl);
    exit;
}


/* ============================================================
 * CHECK SESSION
 * ============================================================ */
function handleCheckSessionAPI(): void
{
    if (!isLoggedIn()) {
        jsonResponse([
            'success'   => false,
            'logged_in' => false,
        ], 200);
    }

    $user = getCurrentUser();
    jsonSuccess([
        'logged_in'    => true,
        'user'         => $user,
        'redirect_url' => getRedirectUrlByRole($user['vai_tro'] ?? ''),
    ]);
}


/* ============================================================
 * HELPERS
 * ============================================================ */
function getRedirectUrlByRole(string $role): string
{
    $base = rtrim(BASE_URL, '/') . '/frontend';
    return $base . '/home/trangchu.html';
}

function registerFailedAttempt(array $user, array $cols): void
{
    if (!isset($cols['so_lan_sai'])) {
        return;
    }

    try {
        $fails = (int)($user['so_lan_sai'] ?? 0) + 1;
        $data  = ['so_lan_sai' => $fails];

        if ($fails >= 5 && isset($cols['khoa_den'])) {
            $data['khoa_den']   = date('Y-m-d H:i:s', time() + 15 * 60);
            $data['so_lan_sai'] = 0;
        }

        Database::update('users', $data, 'id = :id', ['id' => $user['id']]);
    } catch (Throwable $e) {
        error_log('[Login] registerFailedAttempt: ' . $e->getMessage());
    }
}