<?php
/**
 * ============================================================
 *  API: LỊCH SỬ ĐÃ XIN NGHỈ — Danh sách đơn nghỉ phép
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 *  File: backend/api/lichsu.php
 *  Method: GET
 * ============================================================
 *  Params hỗ trợ:
 *   - page   : số trang (mặc định 1)
 *   - status : lọc theo trạng thái (draft|cho_truong_khoa|...)
 *   - nam    : lọc theo năm (mặc định năm hiện tại)
 *   - search : tìm kiếm theo mã đơn / họ tên / lý do
 * ============================================================
 */

if (!ob_get_level()) { ob_start(); }

@header('Content-Type: application/json; charset=utf-8');
@header('X-Content-Type-Options: nosniff');
@header('Cache-Control: no-store, no-cache, must-revalidate');

$__API_DEBUG = true;   // Fix xong đổi thành false

/* ============================================================
 * GLOBAL ERROR HANDLER
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
 * LOAD CONFIG
 * ============================================================ */
$configFile = __DIR__ . '/../config/functions.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Không tìm thấy file cấu hình: ' . $configFile,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $configFile;

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

$currentUser = getCurrentUser();
$currentEmp  = getCurrentEmployee();
$db          = Database::getConnection();

$userRole    = $currentUser['vai_tro']         ?? 'nhan_vien';
$nhanVienId  = (int)($currentUser['nhan_vien_id'] ?? 0);
$khoaPhongId = (int)($currentEmp['khoa_phong_id'] ?? 0);
$currentYear = (int)date('Y');

/* ============================================================
 * FILTER PARAMS
 * ============================================================ */
$statusFilter = trim($_GET['status'] ?? '');
$yearFilter   = (int)($_GET['nam'] ?? $currentYear);
$search       = trim($_GET['search'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 10;
$offset       = ($page - 1) * $perPage;

/* Validate year */
if ($yearFilter < 2000 || $yearFilter > 2100) {
    $yearFilter = $currentYear;
}

/* Whitelist status để tránh SQL bất thường */
$allowedStatuses = [
    'draft', 'cho_truong_khoa', 'cho_phe_duyet',
    'da_duyet', 'tu_choi', 'da_tiep_nhan'
];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

/* ============================================================
 * BUILD WHERE — phân quyền
 * ============================================================ */
$whereSql    = 'WHERE YEAR(np.tu_ngay) = ?';
$whereParams = [$yearFilter];

if ($userRole === 'nhan_vien' || $userRole === 'nhanvien') {
    /* Nhân viên: chỉ xem đơn của chính mình */
    $whereSql .= ' AND np.nhan_vien_id = ?';
    $whereParams[] = $nhanVienId;

} elseif ($userRole === 'truong_khoa' || $userRole === 'truongkhoa') {
    /* Trưởng khoa: xem đơn của khoa mình + đơn của chính mình */
    $whereSql .= ' AND (nv.khoa_phong_id = ? OR np.nhan_vien_id = ?)';
    $whereParams[] = $khoaPhongId;
    $whereParams[] = $nhanVienId;
}
/* admin, giam_doc, ban_lanh_dao, nhan_su → xem hết */

if ($statusFilter !== '') {
    $whereSql .= ' AND np.trang_thai = ?';
    $whereParams[] = $statusFilter;
}

if ($search !== '') {
    $whereSql .= ' AND (nv.ho_ten LIKE ? OR np.ma_don LIKE ? OR np.ly_do LIKE ?)';
    $s = "%{$search}%";
    $whereParams[] = $s;
    $whereParams[] = $s;
    $whereParams[] = $s;
}

/* ============================================================
 * ĐẾM TỔNG
 * ============================================================ */
$totalRows = 0;
try {
    $stmtCount = $db->prepare("
        SELECT COUNT(*)
        FROM nghi_phep np
        JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
        $whereSql
    ");
    $stmtCount->execute($whereParams);
    $totalRows = (int)$stmtCount->fetchColumn();
} catch (Throwable $e) {
    jsonError($__API_DEBUG ? 'Count error: ' . $e->getMessage() : 'Lỗi hệ thống!', 500);
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/* ============================================================
 * LẤY DANH SÁCH — có hỗ trợ cột buoi_nghi_ket_thuc nếu đã migrate
 * ============================================================ */
$leaves = [];
try {
    /* Kiểm tra cột buoi_nghi_ket_thuc có tồn tại không (an toàn khi chưa migrate) */
    $hasBuoiEnd = false;
    try {
        $stmtCol = $db->query("SHOW COLUMNS FROM nghi_phep LIKE 'buoi_nghi_ket_thuc'");
        $hasBuoiEnd = (bool)$stmtCol->fetch();
    } catch (Throwable $e) { /* ignore */ }

    $buoiEndSelect = $hasBuoiEnd ? ', np.buoi_nghi_ket_thuc' : '';

    $sql = "
        SELECT np.id, np.ma_don, np.nhan_vien_id,
               np.tu_ngay, np.den_ngay, np.buoi_nghi $buoiEndSelect,
               np.so_ngay, np.ly_do, np.trang_thai, np.created_at,
               np.ngay_nop_nhan_su,
               nv.ho_ten, nv.ma_nv, nv.chuc_vu,
               kp.ten_khoa
        FROM nghi_phep np
        JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
        LEFT JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
        $whereSql
        ORDER BY np.created_at DESC, np.id DESC
        LIMIT $perPage OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($whereParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $leaves[] = [
            'id'                 => (int)$r['id'],
            'ma_don'             => $r['ma_don'],
            'nhan_vien_id'       => (int)$r['nhan_vien_id'],
            'ho_ten'             => $r['ho_ten'],
            'ma_nv'              => $r['ma_nv'],
            'chuc_vu'            => $r['chuc_vu'],
            'ten_khoa'           => $r['ten_khoa'],
            'tu_ngay'            => $r['tu_ngay'],
            'den_ngay'           => $r['den_ngay'],
            'buoi_nghi'          => $r['buoi_nghi'] ?? 'ca_ngay',
            'buoi_nghi_ket_thuc' => $r['buoi_nghi_ket_thuc'] ?? ($r['buoi_nghi'] ?? 'ca_ngay'),
            'so_ngay'            => (float)$r['so_ngay'],
            'ly_do'              => $r['ly_do'],
            'trang_thai'         => $r['trang_thai'],
            'created_at'         => $r['created_at'],
            'ngay_nop_nhan_su'   => $r['ngay_nop_nhan_su'] ?? null,
        ];
    }
} catch (Throwable $e) {
    jsonError($__API_DEBUG ? 'Fetch error: ' . $e->getMessage() : 'Lỗi hệ thống!', 500);
}

/* ============================================================
 * BADGE PENDING — số đơn chờ xử lý của user hiện tại
 * ============================================================ */
$pendingBadgeCount = 0;
try {
    $stmtP = $db->prepare("
        SELECT COUNT(*) FROM nghi_phep
        WHERE nhan_vien_id = ?
          AND trang_thai IN ('cho_duyet','cho_truong_khoa','cho_nhan_su')
    ");
    $stmtP->execute([$nhanVienId]);
    $pendingBadgeCount = (int)$stmtP->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

/* ============================================================
   ⭐ TÌM ĐƠN ĐẦU TIÊN ĐÃ XÁC NHẬN — KHÔNG phụ thuộc filter/year/page
   ------------------------------------------------------------
   - Query RIÊNG, chỉ áp dụng phân quyền (nhân viên chỉ thấy đơn mình)
   - Lấy đơn MỚI NHẤT trong các đơn đã duyệt / đã tiếp nhận
   - Dùng để bật nút "In" trên FE — in TẤT CẢ đơn đã xác nhận
   ============================================================ */
$firstPrintableId = null;
try {
    $whereFP  = 'WHERE 1=1';
    $paramsFP = [];

    if ($userRole === 'nhan_vien' || $userRole === 'nhanvien') {
        $whereFP .= ' AND np.nhan_vien_id = ?';
        $paramsFP[] = $nhanVienId;
    } elseif ($userRole === 'truong_khoa' || $userRole === 'truongkhoa') {
        $whereFP .= ' AND (nv.khoa_phong_id = ? OR np.nhan_vien_id = ?)';
        $paramsFP[] = $khoaPhongId;
        $paramsFP[] = $nhanVienId;
    }

    $whereFP .= " AND np.trang_thai IN ('da_duyet', 'da_tiep_nhan')";

    $stmtFP = $db->prepare("
        SELECT np.id
        FROM nghi_phep np
        JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
        $whereFP
        ORDER BY np.tu_ngay DESC, np.id DESC
        LIMIT 1
    ");
    $stmtFP->execute($paramsFP);
    $firstPrintableId = $stmtFP->fetchColumn() ?: null;

} catch (Throwable $e) {
    error_log('[LichSu] firstPrintableId: ' . $e->getMessage());
}

/* ============================================================
 * RESPONSE
 * ============================================================ */
jsonSuccess([
    'leaves' => $leaves,
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total_rows'  => $totalRows,
        'total_pages' => $totalPages,
    ],
    'filters' => [
        'status' => $statusFilter,
        'nam'    => $yearFilter,
        'search' => $search,
    ],
    'pending_badge'      => $pendingBadgeCount,
    'role'               => $userRole,
    'nhan_vien_id'       => $nhanVienId,
    'current_year'       => $currentYear,

    /* ⭐ Đơn đầu tiên đã xác nhận — dùng để enable nút "In sổ" trên FE */
    'first_printable_id' => $firstPrintableId ? (int)$firstPrintableId : null,
]);