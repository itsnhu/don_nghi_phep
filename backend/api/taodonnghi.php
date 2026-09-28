<?php
/**
 * ============================================================
 *  API: TẠO ĐƠN NGHỈ PHÉP
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 *  File: backend/api/taodonnghi.php
 * ============================================================
 *  ⚠️  LƯU Ý:
 *   - File phải lưu dạng UTF-8 (KHÔNG BOM)
 *   - Ký tự đầu tiên của file phải là '<' của '<?php'
 *   - Không có dòng trống / khoảng trắng trước '<?php'
 * ============================================================
 */

/* Bắt đầu output buffering ngay từ đầu — tránh HTML rò rỉ */
if (!ob_get_level()) { ob_start(); }

/* Debug: BẬT khi chẩn đoán, TẮT khi production */
$__API_DEBUG = true;

/* Header JSON phải set TRƯỚC khi bất cứ output nào chạy */
@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');
@header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
@header('Pragma: no-cache');

/* ============================================================
 * GLOBAL ERROR HANDLERS
 * ============================================================ */

/* Bắt Exception & Error → trả JSON */
set_exception_handler(function (Throwable $ex) use ($__API_DEBUG) {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $__API_DEBUG
            ? 'Exception: ' . $ex->getMessage()
                . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine()
            : 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau!',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

/* Bắt Fatal Error ở shutdown */
register_shutdown_function(function () use ($__API_DEBUG) {
    $err = error_get_last();
    if ($err && in_array($err['type'],
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {

        while (ob_get_level()) { ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            @header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'message' => $__API_DEBUG
                ? 'Fatal: ' . $err['message']
                    . ' @ ' . basename($err['file']) . ':' . $err['line']
                : 'Lỗi nghiêm trọng từ server!',
        ], JSON_UNESCAPED_UNICODE);
    }
});

/* Warning/Notice → chỉ log, KHÔNG throw (tránh phá JSON) */
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    error_log('[TaoDonNghi] ' . $errstr . ' @ ' . basename($errfile) . ':' . $errline);
    return true;
});

/* ============================================================
 * LOAD CONFIG
 * ============================================================ */
$configPath = __DIR__ . '/../config/functions.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Không tìm thấy file cấu hình: ' . $configPath,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $configPath;

/* ============================================================
 * AUTH GUARD
 * ============================================================ */
if (!function_exists('isLoggedIn')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Hàm isLoggedIn chưa được định nghĩa trong functions.php',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    jsonError('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại!', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Phương thức không hợp lệ! Chỉ chấp nhận POST.', 405);
}

if (function_exists('verifyCsrfRequest')) {
    verifyCsrfRequest();
}

$currentUser = getCurrentUser();
$currentEmp  = getCurrentEmployee();

if (!$currentEmp || empty($currentEmp['id'])) {
    jsonError('Tài khoản chưa liên kết hồ sơ nhân viên!', 403);
}

$nhanVienId = (int)$currentEmp['id'];
$db = Database::getConnection();

/* ============================================================
 * LẤY DỮ LIỆU POST
 * ============================================================ */
$tuNgay          = trim($_POST['tu_ngay']  ?? '');
$denNgay         = trim($_POST['den_ngay'] ?? '');
$soNgay          = (float)($_POST['so_ngay'] ?? 0);
$lyDo            = trim($_POST['ly_do']    ?? '');
$actionType      = $_POST['action_type']   ?? 'submit';
$chuKyBase64     = trim($_POST['chu_ky_nguoi_nghi'] ?? '');
$signatureMethod = $_POST['signature_method'] ?? 'draw';

/* ============================================================
 * VALIDATE
 * ============================================================ */
$errors = [];

if ($tuNgay === '')  $errors[] = 'Vui lòng chọn ngày bắt đầu nghỉ phép.';
if ($denNgay === '') $errors[] = 'Vui lòng chọn ngày kết thúc nghỉ phép.';

if ($tuNgay !== '' && $denNgay !== '') {
    $t1 = strtotime($tuNgay);
    $t2 = strtotime($denNgay);
    if ($t1 === false || $t2 === false) {
        $errors[] = 'Định dạng ngày không hợp lệ (phải là YYYY-MM-DD).';
    } elseif ($t2 < $t1) {
        $errors[] = 'Ngày kết thúc không được nhỏ hơn ngày bắt đầu.';
    }
}

if ($soNgay <= 0)   $errors[] = 'Số ngày nghỉ phải lớn hơn 0.';
if ($soNgay > 365)  $errors[] = 'Số ngày nghỉ không được vượt quá 365.';
if ($lyDo === '')   $errors[] = 'Vui lòng nhập lý do nghỉ phép.';
if (mb_strlen($lyDo) > 1000) $errors[] = 'Lý do nghỉ phép quá dài (tối đa 1000 ký tự).';

if (!empty($errors)) {
    jsonError($errors[0], 422, ['errors' => $errors]);
}

/* ============================================================
 * XỬ LÝ CHỮ KÝ
 * ============================================================ */
$chuKyPath = null;
$sigDir    = dirname(__DIR__, 2) . '/frontend/uploads/chuky';

if ($signatureMethod === 'upload') {

    /* Upload file ảnh */
    if (!isset($_FILES['file_chu_ky'])
        || !is_array($_FILES['file_chu_ky'])
        || $_FILES['file_chu_ky']['error'] !== UPLOAD_ERR_OK) {
        jsonError('Vui lòng chọn file ảnh chữ ký hợp lệ.', 422);
    }

    $file = $_FILES['file_chu_ky'];

    /* Giới hạn 5MB */
    if ($file['size'] > 5 * 1024 * 1024) {
        jsonError('File chữ ký quá lớn (tối đa 5MB).', 422);
    }

    /* Xác định extension theo MIME thật */
    $ext     = 'png';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            jsonError('File chữ ký không đúng định dạng ảnh (chỉ JPG/PNG/WEBP/GIF).', 422);
        }
        $ext = $allowed[$mime];
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            jsonError('File chữ ký không đúng định dạng ảnh.', 422);
        }
        $ext = $allowed[$mime];
    }

    if (!is_dir($sigDir)) {
        @mkdir($sigDir, 0775, true);
    }
    if (!is_dir($sigDir) || !is_writable($sigDir)) {
        error_log('[TaoDonNghi] Không thể tạo/ghi thư mục chữ ký: ' . $sigDir);
        jsonError('Không thể lưu chữ ký. Vui lòng liên hệ kỹ thuật!', 500);
    }

    $fileName = 'sign_' . $nhanVienId . '_' . time() . '_'
              . bin2hex(random_bytes(3)) . '.' . $ext;
    $destPath = rtrim($sigDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;

    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        $chuKyPath = 'frontend/uploads/chuky/' . $fileName;
    } else {
        error_log('[TaoDonNghi] move_uploaded_file thất bại: ' . $destPath);
        jsonError('Không thể lưu file chữ ký tải lên.', 500);
    }

} else {

    /* Vẽ canvas → base64 → ghi file PNG */
    if ($chuKyBase64 !== '' && strpos($chuKyBase64, 'data:image') === 0) {

        $parts = explode(',', $chuKyBase64, 2);
        if (isset($parts[1])) {
            $decoded = base64_decode($parts[1], false);

            if ($decoded !== false && strlen($decoded) > 0) {

                /* Giới hạn 2MB */
                if (strlen($decoded) > 2 * 1024 * 1024) {
                    jsonError('Chữ ký vẽ quá lớn. Vui lòng vẽ đơn giản hơn.', 422);
                }

                if (!is_dir($sigDir)) {
                    @mkdir($sigDir, 0775, true);
                }
                if (!is_dir($sigDir) || !is_writable($sigDir)) {
                    error_log('[TaoDonNghi] Không thể tạo/ghi thư mục chữ ký: ' . $sigDir);
                    jsonError('Không thể lưu chữ ký. Vui lòng liên hệ kỹ thuật!', 500);
                }

                $fileName = 'sign_' . $nhanVienId . '_' . time() . '_'
                          . bin2hex(random_bytes(3)) . '.png';
                $filePath = rtrim($sigDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;

                if (file_put_contents($filePath, $decoded) !== false) {
                    $chuKyPath = 'frontend/uploads/chuky/' . $fileName;
                } else {
                    error_log('[TaoDonNghi] file_put_contents thất bại: ' . $filePath);
                }
            }
        }
    }
    /* Không bắt buộc — cho phép $chuKyPath = null */
}

/* ============================================================
 * SINH MÃ ĐƠN
 * ============================================================ */
$maDon = 'NP-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));

/* ============================================================
 * TRẠNG THÁI
 * ============================================================ */
$trangThai = ($actionType === 'draft') ? 'draft' : 'cho_truong_khoa';
$buoiNghi  = ($soNgay == 0.5) ? 'sang' : 'ca_ngay';

/* ============================================================
 * INSERT — bảng nghi_phep
 * ============================================================ */
try {
    $sql = "INSERT INTO nghi_phep 
                (ma_don, nhan_vien_id, tu_ngay, den_ngay, buoi_nghi,
                 so_ngay, ly_do, chu_ky_nguoi_nghi, trang_thai, created_at) 
            VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $maDon,
        $nhanVienId,
        $tuNgay,
        $denNgay,
        $buoiNghi,
        $soNgay,
        $lyDo,
        $chuKyPath,
        $trangThai,
    ]);

    $newId = (int)$db->lastInsertId();

} catch (Throwable $e) {
    error_log('[TaoDonNghi] INSERT error: ' . $e->getMessage());
    jsonError(
        $__API_DEBUG
            ? 'INSERT Error: ' . $e->getMessage()
            : 'Không thể tạo đơn. Vui lòng thử lại!',
        500
    );
}

/* ============================================================
 * GHI LỊCH SỬ — không fail nếu lỗi
 * ============================================================ */
if (function_exists('recordLeaveHistory')) {
    try {
        recordLeaveHistory(
            $newId,
            (int)($currentUser['id'] ?? 0),
            ($actionType === 'draft') ? 'Tạo bản nháp' : 'Tạo và gửi',
            null,
            $trangThai,
            "Nhân viên tạo đơn nghỉ {$soNgay} ngày"
        );
    } catch (Throwable $e) {
        error_log('[TaoDonNghi] recordLeaveHistory: ' . $e->getMessage());
    }
}

/* ============================================================
 * GỬI THÔNG BÁO CHO TRƯỞNG KHOA — không fail nếu lỗi
 * ============================================================ */
if ($trangThai === 'cho_truong_khoa'
    && function_exists('createNotification')
    && !empty($currentEmp['khoa_phong_id'])) {

    try {
        $stmtTK = $db->prepare('
            SELECT u.id 
            FROM users u
            JOIN nhan_vien nv ON u.nhan_vien_id = nv.id
            WHERE nv.khoa_phong_id = ? AND u.vai_tro = "truong_khoa"
        ');
        $stmtTK->execute([$currentEmp['khoa_phong_id']]);

        while ($tk = $stmtTK->fetch()) {
            createNotification(
                (int)$tk['id'],
                'Có giấy nghỉ phép mới',
                "Nhân viên {$currentEmp['ho_ten']} vừa tạo đơn nghỉ {$soNgay} ngày ({$maDon})",
                '/nhanvien/duyet-truong-khoa.html'
            );
        }
    } catch (Throwable $e) {
        error_log('[TaoDonNghi] createNotification: ' . $e->getMessage());
    }
}

/* ============================================================
 * TRẢ KẾT QUẢ — dọn buffer, trả JSON sạch
 * ============================================================ */
while (ob_get_level() > 0) { ob_end_clean(); }

echo json_encode([
    'success' => true,
    'message' => 'Tạo giấy nghỉ phép thành công! Mã đơn: ' . $maDon,
    'data' => [
        'id'           => $newId,
        'ma_don'       => $maDon,
        'trang_thai'   => $trangThai,
        'redirect_url' => 'lichsu.html',
    ],
    /* Giữ key cũ để tương thích với JS hiện tại */
    'id'           => $newId,
    'ma_don'       => $maDon,
    'redirect_url' => 'lichsu.html',
], JSON_UNESCAPED_UNICODE);
exit;