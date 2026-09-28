<?php
/**
 * ============================================================
 *  CẤU HÌNH ỨNG DỤNG & THÔNG TIN BỆNH VIỆN
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 * ------------------------------------------------------------
 *  - Output buffering sớm
 *  - Auto-detect BASE_URL
 *  - define() có guard (!defined) tránh warning
 *  - Cấu hình session an toàn
 *  - Phân biệt môi trường dev/prod cho error_reporting
 *  - Set timezone + security headers
 * ============================================================
 */

/* ============================================================
 * 1. OUTPUT BUFFERING (phải đặt sớm nhất)
 * ============================================================ */
if (!ob_get_level()) {
    ob_start();
}

/* ============================================================
 * 2. MÔI TRƯỜNG (dev/prod) — quyết định error_reporting
 * ============================================================ */
if (!defined('APP_ENV')) {
    define('APP_ENV', getenv('APP_ENV') ?: 'development');
}
if (!defined('IS_DEV_ENV')) {
    define('IS_DEV_ENV', APP_ENV === 'development');
}

/* Bật/tắt hiển thị lỗi theo môi trường */
if (IS_DEV_ENV) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

/* ============================================================
 * 3. TIMEZONE — đồng bộ PHP với MySQL (+07:00)
 * ============================================================ */
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Ho_Chi_Minh');
}
date_default_timezone_set(APP_TIMEZONE);

/* ============================================================
 * 4. AUTO-DETECT BASE_URL
 * ============================================================ */
if (!defined('BASE_URL')) {

    $protocol = 'http://';
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    ) {
        $protocol = 'https://';
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    /* Chuẩn hóa dấu / trên mọi HĐH */
    $scriptDir = str_replace('\\', '/', dirname(__DIR__));   // → .../ql_nghiphep/backend
    $docRoot   = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');

    /* Nếu project nằm dưới document root → lấy phần relative */
    $relative = '';
    if ($docRoot !== '' && strpos($scriptDir, $docRoot) === 0) {
        $relative = substr($scriptDir, strlen($docRoot));
        /* Bỏ hậu tố /backend nếu có */
        $relative = preg_replace('#/backend$#', '', $relative);
    } else {
        /* Fallback cho trường hợp XAMPP alias */
        $relative = '/ql_nghiphep';
    }

    $relative = rtrim($relative, '/');

    define('BASE_URL', $protocol . $host . $relative);
}

/* ============================================================
 * 5. THÔNG TIN ỨNG DỤNG & BỆNH VIỆN
 * ============================================================ */
if (!defined('APP_NAME'))          define('APP_NAME',          'BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP');
if (!defined('APP_SHORT_NAME'))    define('APP_SHORT_NAME',    'BV TÂM TRÍ ĐỒNG THÁP');
if (!defined('APP_SUBTITLE'))      define('APP_SUBTITLE',      'Hệ Thống Quản Lý Giấy Nghỉ Phép');
if (!defined('APP_VERSION'))       define('APP_VERSION',       '1.0.0');

if (!defined('HOSPITAL_NAME'))     define('HOSPITAL_NAME',     'BỆNH VIỆN TÂM TRÍ ĐỒNG THÁP');
if (!defined('HOSPITAL_FULL_NAME'))define('HOSPITAL_FULL_NAME','BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP');
if (!defined('HOSPITAL_ADDRESS'))  define('HOSPITAL_ADDRESS',  'Đồng Tháp, Việt Nam');
if (!defined('HOSPITAL_PHONE'))    define('HOSPITAL_PHONE',    '0277.xxx.xxx');
if (!defined('HOSPITAL_EMAIL'))    define('HOSPITAL_EMAIL',    'contact@bvdtt.vn');
if (!defined('HOSPITAL_DIRECTOR')) define('HOSPITAL_DIRECTOR', 'ThS. BS. Đinh Tấn Tài');

if (!defined('CURRENT_YEAR'))      define('CURRENT_YEAR',      (int)date('Y'));

/* ============================================================
 * 6. ĐƯỜNG DẪN THƯ MỤC
 *    - ROOT_PATH: gốc project (ql_nghiphep/)
 *    - BACKEND_PATH: backend/
 *    - FRONTEND_PATH: frontend/
 *    - UPLOAD_DIR: frontend/uploads/  ← đúng theo cây thư mục
 * ============================================================ */
if (!defined('ROOT_PATH'))     define('ROOT_PATH',     dirname(__DIR__, 2));
if (!defined('BACKEND_PATH'))  define('BACKEND_PATH',  dirname(__DIR__));
if (!defined('FRONTEND_PATH')) define('FRONTEND_PATH', ROOT_PATH . '/frontend');

if (!defined('UPLOAD_DIR'))     define('UPLOAD_DIR',     FRONTEND_PATH . '/uploads');
if (!defined('UPLOAD_IMG_DIR')) define('UPLOAD_IMG_DIR', UPLOAD_DIR . '/img');
if (!defined('SIGNATURE_DIR'))  define('SIGNATURE_DIR',  UPLOAD_DIR . '/signatures');
if (!defined('CHUKY_DIR'))      define('CHUKY_DIR',      UPLOAD_DIR . '/chuky');

if (!defined('LOG_DIR'))        define('LOG_DIR',        BACKEND_PATH . '/logs');

/* URL tương ứng (dùng trong HTML) */
if (!defined('UPLOAD_URL'))     define('UPLOAD_URL',     BASE_URL . '/frontend/uploads');
if (!defined('IMG_URL'))        define('IMG_URL',        UPLOAD_URL . '/img');

/* ============================================================
 * 7. TỰ ĐỘNG TẠO THƯ MỤC CẦN THIẾT (nếu chưa có)
 * ============================================================ */
$__dirs = [UPLOAD_DIR, UPLOAD_IMG_DIR, SIGNATURE_DIR, CHUKY_DIR, LOG_DIR];
foreach ($__dirs as $__dir) {
    if (!is_dir($__dir)) {
        if (!@mkdir($__dir, 0775, true) && !is_dir($__dir)) {
            if (IS_DEV_ENV) {
                error_log("[app.php] Không thể tạo thư mục: {$__dir}");
            }
        }
    }
}
unset($__dirs, $__dir);

/* ============================================================
 * 8. CẤU HÌNH SESSION AN TOÀN
 * ============================================================ */
if (!defined('SESSION_NAME'))     define('SESSION_NAME',     'BVDTT_SESSID');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 7200); // 2 giờ

if (session_status() === PHP_SESSION_NONE) {

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);

    /* Bật cookie secure nếu chạy HTTPS */
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/* ============================================================
 * 9. SECURITY HEADERS CƠ BẢN
 * ============================================================ */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    /* Bỏ comment nếu muốn bật CSP chặt (cần test kỹ) */
    // header("Content-Security-Policy: default-src 'self' https: 'unsafe-inline' 'unsafe-eval' data: blob:");
}