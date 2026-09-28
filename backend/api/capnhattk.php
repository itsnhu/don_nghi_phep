<?php
/**
 * ============================================================
 *  API: CẬP NHẬT THÔNG TIN TÀI KHOẢN + UPLOAD AVATAR
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 * ------------------------------------------------------------
 *  ĐẶC ĐIỂM:
 *    ✓ Global error handler → LUÔN trả JSON, không bao giờ 500 trắng
 *    ✓ Tự kiểm tra schema DB (anh_dai_dien, ngay_sinh, updated_at)
 *    ✓ Whitelist MIME ảnh (JPG/PNG/WEBP/GIF), giới hạn 2MB
 *    ✓ CSRF bảo vệ (dùng hàm từ functions.php)
 *    ✓ Đồng bộ session sau khi update
 *    ✓ Tích hợp hoàn toàn với functions.php + ketnoisql.php + app.php
 * ============================================================
 */

/* ============================================================
 * 1. OUTPUT BUFFERING + HEADER JSON SỚM
 * ============================================================ */
if (!ob_get_level()) {
    ob_start();
}

@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');
@header('Cache-Control: no-store, no-cache, must-revalidate');

/* ============================================================
 * 2. GLOBAL ERROR HANDLER
 * ============================================================ */
$__API_DEBUG = defined('IS_DEV_ENV') ? IS_DEV_ENV : false;

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
 * 4. AUTH GUARD
 * ============================================================ */
if (!isLoggedIn()) {
    jsonError('Vui lòng đăng nhập để thực hiện!', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Phương thức yêu cầu không hợp lệ! Chỉ chấp nhận POST.', 405);
}

verifyCsrfRequest();

/* ============================================================
 * 5. LẤY USER + EMPLOYEE
 * ============================================================ */
$currentEmp = getCurrentEmployee();

if (!$currentEmp || empty($currentEmp['id'])) {
    jsonError('Không tìm thấy hồ sơ nhân viên liên kết!', 404);
}

$nhanVienId = (int)$currentEmp['id'];

/* ============================================================
 * 6. LẤY DỮ LIỆU POST
 * ============================================================ */
$hoTen       = trim((string)($_POST['ho_ten']       ?? ''));
$email       = trim((string)($_POST['email']        ?? ''));
$soDienThoai = trim((string)($_POST['so_dien_thoai'] ?? ''));
$ngaySinh    = trim((string)($_POST['ngay_sinh']    ?? ''));

/* ============================================================
 * 7. VALIDATE
 * ============================================================ */
if ($hoTen === '') {
    jsonError('Họ và tên không được để trống!', 422);
}
if (mb_strlen($hoTen) > 100) {
    jsonError('Họ và tên quá dài (tối đa 100 ký tự)!', 422);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError('Địa chỉ email không đúng định dạng!', 422);
}
if ($soDienThoai !== '' && !preg_match('/^[0-9+\s\-\.]{8,20}$/', $soDienThoai)) {
    jsonError('Số điện thoại không hợp lệ!', 422);
}
if ($ngaySinh !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ngaySinh)) {
    jsonError('Ngày sinh không đúng định dạng (YYYY-MM-DD)!', 422);
}

/* ============================================================
 * 8. CHECK SCHEMA
 * ============================================================ */
$db = Database::getConnection();

$cols = [];
try {
    $stmt = $db->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'nhan_vien'
    ");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cols[$row['COLUMN_NAME']] = true;
    }
} catch (Throwable $e) {
    jsonError('Không kiểm tra được cấu trúc bảng nhân viên!', 500);
}

$hasAvatarCol   = isset($cols['anh_dai_dien']);
$hasNgaySinhCol = isset($cols['ngay_sinh']);
$hasUpdatedCol  = isset($cols['updated_at']);

/* ============================================================
 * 9. UPLOAD AVATAR (nếu có)
 * ============================================================ */
$avatarRelativePath = null;

$hasUploadedFile = isset($_FILES['anh_dai_dien'])
    && is_array($_FILES['anh_dai_dien'])
    && $_FILES['anh_dai_dien']['error'] !== UPLOAD_ERR_NO_FILE;

if ($hasUploadedFile) {

    if (!$hasAvatarCol) {
        jsonError(
            'Cơ sở dữ liệu chưa có cột "anh_dai_dien". ' .
            'Vui lòng chạy SQL: ALTER TABLE nhan_vien ADD COLUMN anh_dai_dien VARCHAR(255) NULL;',
            500
        );
    }

    $file = $_FILES['anh_dai_dien'];

    /* 9.1 — Lỗi upload */
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'Ảnh vượt quá giới hạn upload_max_filesize của PHP!',
            UPLOAD_ERR_FORM_SIZE  => 'Ảnh vượt quá giới hạn MAX_FILE_SIZE của form!',
            UPLOAD_ERR_PARTIAL    => 'Ảnh chỉ được upload một phần!',
            UPLOAD_ERR_NO_TMP_DIR => 'Server thiếu thư mục tạm!',
            UPLOAD_ERR_CANT_WRITE => 'Không ghi được file lên đĩa!',
            UPLOAD_ERR_EXTENSION  => 'Upload bị chặn bởi extension PHP!',
        ];
        jsonError($uploadErrors[$file['error']] ?? 'Lỗi upload không xác định!', 422);
    }

    /* 9.2 — Giới hạn 2MB */
    if ((int)$file['size'] > 2 * 1024 * 1024) {
        jsonError('Ảnh đại diện không được vượt quá 2MB!', 422);
    }

    /* 9.3 — Whitelist MIME bằng finfo */
    if (!class_exists('finfo')) {
        jsonError('Server thiếu extension "fileinfo". Vui lòng bật trong php.ini!', 500);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (!array_key_exists($mime, $allowedMimes)) {
        jsonError('Chỉ chấp nhận ảnh JPG, PNG, WEBP hoặc GIF! (MIME: ' . $mime . ')', 422);
    }

    $ext = $allowedMimes[$mime];

    /* 9.4 — Thư mục đích: frontend/uploads/avatars */
    $avatarDir = defined('UPLOAD_DIR')
        ? (UPLOAD_DIR . '/avatars')
        : (dirname(__DIR__, 2) . '/frontend/uploads/avatars');

    if (!is_dir($avatarDir)) {
        if (!@mkdir($avatarDir, 0775, true) && !is_dir($avatarDir)) {
            jsonError('Không tạo được thư mục upload: ' . $avatarDir, 500);
        }
    }
    if (!is_writable($avatarDir)) {
        @chmod($avatarDir, 0775);
        if (!is_writable($avatarDir)) {
            jsonError('Thư mục upload không có quyền ghi: ' . $avatarDir, 500);
        }
    }

    /* 9.5 — Xóa avatar cũ */
    if (!empty($currentEmp['anh_dai_dien'])) {
        $oldRelative = ltrim((string)$currentEmp['anh_dai_dien'], '/');
        $oldAbs = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/' . $oldRelative;
        if (is_file($oldAbs)) @unlink($oldAbs);
    }

    /* 9.6 — Tên file mới */
    $newName = sprintf('nv_%d_%s.%s', $nhanVienId, bin2hex(random_bytes(8)), $ext);
    $destAbs = $avatarDir . '/' . $newName;

    if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
        jsonError('Không lưu được ảnh lên server! Kiểm tra quyền thư mục.', 500);
    }

    @chmod($destAbs, 0644);

    $avatarRelativePath = 'frontend/uploads/avatars/' . $newName;
}

/* ============================================================
 * 10. BUILD SQL ĐỘNG + UPDATE
 * ============================================================ */
try {
    $setClauses = ['ho_ten = ?', 'email = ?', 'so_dien_thoai = ?'];
    $params     = [$hoTen, $email ?: null, $soDienThoai ?: null];

    if ($hasNgaySinhCol) {
        $setClauses[] = 'ngay_sinh = ?';
        $params[]     = $ngaySinh ?: null;
    }

    if ($hasAvatarCol && $avatarRelativePath !== null) {
        $setClauses[] = 'anh_dai_dien = ?';
        $params[]     = $avatarRelativePath;
    }

    if ($hasUpdatedCol) {
        $setClauses[] = 'updated_at = NOW()';
    }

    $params[] = $nhanVienId;

    $sql  = 'UPDATE nhan_vien SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    /* ============================================================
     * 11. SYNC SESSION
     * ============================================================ */
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        $_SESSION['user'] = [];
    }
    $_SESSION['user']['ho_ten']        = $hoTen;
    $_SESSION['user']['email']         = $email;
    $_SESSION['user']['so_dien_thoai'] = $soDienThoai;

    if ($avatarRelativePath !== null) {
        $_SESSION['user']['anh_dai_dien'] = $avatarRelativePath;
    }

    /* ============================================================
     * 12. RESPONSE
     * ============================================================ */
    $avatarUrl = null;
    if ($avatarRelativePath !== null) {
        $avatarUrl = rtrim(BASE_URL, '/') . '/' . $avatarRelativePath . '?t=' . time();
    }

    jsonSuccess([
        'avatar_url'      => $avatarUrl,
        'reload_required' => true,
    ], 'Cập nhật thông tin tài khoản thành công!');

} catch (PDOException $ex) {
    error_log('[CapNhatTK] SQL: ' . $ex->getMessage());
    jsonError(
        $__API_DEBUG
            ? 'SQL Error: ' . $ex->getMessage()
            : 'Lỗi cập nhật cơ sở dữ liệu. Vui lòng thử lại!',
        500
    );
} catch (Throwable $ex) {
    error_log('[CapNhatTK] Error: ' . $ex->getMessage());
    jsonError(
        $__API_DEBUG
            ? 'Error: ' . $ex->getMessage()
            : 'Đã xảy ra lỗi. Vui lòng thử lại!',
        500
    );
}