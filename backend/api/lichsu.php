<?php
/**
 * ============================================================
 *  API: LỊCH SỬ ĐÃ XIN NGHỈ — Danh sách đơn nghỉ phép
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
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
require_once __DIR__ . '/../config/functions.php';

/* ============================================================
 * AUTH GUARD
 * ============================================================ */
if (!isLoggedIn()) {
    jsonError('Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại!', 401);
}

$currentUser = getCurrentUser();
$currentEmp  = getCurrentEmployee();
$db          = Database::getConnection();

$userRole   = $currentUser['vai_tro']   ?? 'nhan_vien';
$nhanVienId = (int)($currentUser['nhan_vien_id'] ?? 0);
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

/* ============================================================
 * BUILD WHERE — phân quyền
 * ============================================================ */
$whereSql  = 'WHERE YEAR(np.tu_ngay) = ?';
$whereParams = [$yearFilter];

if ($userRole === 'nhan_vien' || $userRole === 'nhanvien') {
    $whereSql .= ' AND np.nhan_vien_id = ?';
    $whereParams[] = $nhanVienId;
} elseif ($userRole === 'truong_khoa' || $userRole === 'truongkhoa') {
    $whereSql .= ' AND (nv.khoa_phong_id = ? OR np.nhan_vien_id = ?)';
    $whereParams[] = (int)($currentEmp['khoa_phong_id'] ?? 0);
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
 * LẤY DANH SÁCH
 * ============================================================ */
$leaves = [];
try {
    $sql = "
        SELECT np.id, np.ma_don, np.nhan_vien_id,
               np.tu_ngay, np.den_ngay, np.buoi_nghi, np.so_ngay,
               np.ly_do, np.trang_thai, np.created_at,
               np.ngay_nop_nhan_su,
               nv.ho_ten, nv.ma_nv, nv.chuc_vu,
               kp.ten_khoa
        FROM nghi_phep np
        JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
        LEFT JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
        $whereSql
        ORDER BY np.created_at DESC
        LIMIT $perPage OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($whereParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $leaves[] = [
            'id'                => (int)$r['id'],
            'ma_don'            => $r['ma_don'],
            'nhan_vien_id'      => (int)$r['nhan_vien_id'],
            'ho_ten'            => $r['ho_ten'],
            'ma_nv'             => $r['ma_nv'],
            'chuc_vu'           => $r['chuc_vu'],
            'ten_khoa'          => $r['ten_khoa'],
            'tu_ngay'           => $r['tu_ngay'],
            'den_ngay'          => $r['den_ngay'],
            'buoi_nghi'         => $r['buoi_nghi'],
            'so_ngay'           => (float)$r['so_ngay'],
            'ly_do'             => $r['ly_do'],
            'trang_thai'        => $r['trang_thai'],
            'created_at'        => $r['created_at'],
            'ngay_nop_nhan_su'  => $r['ngay_nop_nhan_su'] ?? null,
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
    'pending_badge' => $pendingBadgeCount,
    'role'          => $userRole,
    'nhan_vien_id'  => $nhanVienId,
    'current_year'  => $currentYear,
]);