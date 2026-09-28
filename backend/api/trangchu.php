<?php
/**
 * ============================================================
 *  API XỬ LÝ DỮ LIỆU TRANG CHỦ (HOME / DASHBOARD)
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 * ------------------------------------------------------------
 *  Routing (qua $_GET['action']):
 *    check          → Kiểm tra đăng nhập + lấy user/employee
 *    dashboard      → Stats + danh sách đơn nghỉ phép (mặc định)
 *    notifications  → Danh sách thông báo của user
 *    mark_all_read  → Đánh dấu tất cả thông báo đã đọc
 * ============================================================
 */

require_once __DIR__ . '/../config/functions.php';

$action = $_GET['action'] ?? 'dashboard';

switch ($action) {

    case 'check':
        handleCheck();
        break;

    case 'dashboard':
        handleDashboard();
        break;

    case 'notifications':
        handleNotifications();
        break;

    case 'mark_all_read':
        handleMarkAllRead();
        break;

    default:
        jsonError('Hành động không hợp lệ!', 400);
}


/* ============================================================
 * ACTION: CHECK — Kiểm tra đăng nhập + trả user/employee
 * ============================================================ */
function handleCheck(): void
{
    if (!isLoggedIn()) {
        jsonResponse([
            'success'   => false,
            'logged_in' => false,
        ], 200);
    }

    $user = getCurrentUser();
    $emp  = getCurrentEmployee();

    jsonSuccess([
        'logged_in' => true,
        'user'      => [
            'id'           => $user['id']           ?? null,
            'username'     => $user['username']     ?? null,
            'vai_tro'      => $user['vai_tro']      ?? 'nhan_vien',
            'nhan_vien_id' => $user['nhan_vien_id'] ?? null,
            'ho_ten'       => $user['ho_ten']       ?? null,
        ],
        'employee'  => $emp ? [
            'id'            => $emp['id']            ?? null,
            'ho_ten'        => $emp['ho_ten']        ?? null,
            'ma_nv'         => $emp['ma_nv']         ?? null,
            'email'         => $emp['email']         ?? null,
            'so_dien_thoai' => $emp['so_dien_thoai'] ?? null,
            'ngay_sinh'     => $emp['ngay_sinh']     ?? null,
            'chuc_vu'       => $emp['chuc_vu']       ?? null,
            'ten_khoa'      => $emp['ten_khoa']      ?? null,
            'ma_khoa'       => $emp['ma_khoa']       ?? null,
        ] : null,
        'redirect_url' => getRedirectUrlByRole($user['vai_tro'] ?? ''),
    ]);
}


/* ============================================================
 * ACTION: DASHBOARD — Stats + danh sách đơn nghỉ phép
 * ============================================================ */
function handleDashboard(): void
{
    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }

    $user  = getCurrentUser();
    $emp   = getCurrentEmployee();

    $userId      = (int)($user['id']           ?? 0);
    $nhanVienId  = (int)($user['nhan_vien_id'] ?? 0);
    $role        = (string)($user['vai_tro']   ?? 'nhan_vien');
    $currentYear = defined('CURRENT_YEAR') ? CURRENT_YEAR : (int)date('Y');

    /* ---------- Pagination ---------- */
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 10;
    $offset  = ($page - 1) * $perPage;

    $db = Database::getConnection();

    /* ---------- 1. Xây WHERE theo role ---------- */
    [$whereSql, $params] = buildLeavesWhere($role, $nhanVienId, $emp);

    /* ---------- 2. Đếm tổng số dòng ---------- */
    $totalRows = 0;
    try {
        $stmtCount = $db->prepare("
            SELECT COUNT(*)
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            $whereSql
        ");
        $stmtCount->execute($params);
        $totalRows = (int)$stmtCount->fetchColumn();
    } catch (Throwable $e) {
        error_log('[TrangChu] Count error: ' . $e->getMessage());
    }

    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page   = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    /* ---------- 3. Lấy danh sách đơn ---------- */
    $recentLeaves = [];
    try {
        $sql = "
            SELECT np.id, np.ma_don, np.tu_ngay, np.den_ngay, np.so_ngay,
                   np.trang_thai, np.created_at,
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
        $stmt->execute($params);
        $recentLeaves = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[TrangChu] Fetch leaves error: ' . $e->getMessage());
    }

    /* ---------- 4. Tính stats phép năm ---------- */
    $stats = buildStats($nhanVienId, $currentYear);

    /* ---------- 5. Trả JSON ---------- */
    jsonSuccess([
        'year'     => $currentYear,
        'user'     => [
            'id'           => $userId,
            'username'     => $user['username']     ?? null,
            'vai_tro'      => $role,
            'nhan_vien_id' => $nhanVienId ?: null,
        ],
        'employee' => $emp ? [
            'id'            => $emp['id']            ?? null,
            'ho_ten'        => $emp['ho_ten']        ?? null,
            'ma_nv'         => $emp['ma_nv']         ?? null,
            'email'         => $emp['email']         ?? null,
            'so_dien_thoai' => $emp['so_dien_thoai'] ?? null,
            'ngay_sinh'     => $emp['ngay_sinh']     ?? null,
            'chuc_vu'       => $emp['chuc_vu']       ?? null,
            'ten_khoa'      => $emp['ten_khoa']      ?? null,
            'ma_khoa'       => $emp['ma_khoa']       ?? null,
        ] : null,
        'stats'         => $stats,
        'recent_leaves' => array_map('normalizeLeaveRow', $recentLeaves),
        'pagination'    => [
            'page'       => $page,
            'per_page'   => $perPage,
            'total_rows' => $totalRows,
            'total_pages'=> $totalPages,
        ],
    ]);
}


/* ============================================================
 * ACTION: NOTIFICATIONS — Danh sách thông báo
 * ============================================================ */
function handleNotifications(): void
{
    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }

    $user   = getCurrentUser();
    $userId = (int)($user['id'] ?? 0);

    $list        = getUnreadNotifications($userId, 20);
    $unreadCount = countUnreadNotifications($userId);

    /* Nếu không có thông báo chưa đọc, lấy 5 cái gần nhất (đã đọc) */
    if (empty($list)) {
        $list = getAllNotifications($userId, 5, 0);
    }

    jsonSuccess([
        'unread_count'  => $unreadCount,
        'notifications' => array_map(function ($n) {
            return [
                'id'         => (int)($n['id'] ?? 0),
                'tieu_de'    => $n['tieu_de']    ?? '',
                'noi_dung'   => $n['noi_dung']   ?? '',
                'link'       => $n['link']       ?? null,
                'da_doc'     => (int)($n['da_doc'] ?? 0),
                'created_at' => isset($n['created_at'])
                    ? formatDateTime($n['created_at'])
                    : '',
            ];
        }, $list),
    ]);
}


/* ============================================================
 * ACTION: MARK_ALL_READ — Đánh dấu tất cả thông báo đã đọc
 * ============================================================ */
function handleMarkAllRead(): void
{
    if (!isLoggedIn()) {
        jsonError('Vui lòng đăng nhập!', 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Phương thức không hợp lệ!', 405);
    }

    $user   = getCurrentUser();
    $userId = (int)($user['id'] ?? 0);

    markAllNotificationsAsRead($userId);

    jsonSuccess([], 'Đã đánh dấu tất cả thông báo đã đọc.');
}


/* ============================================================
 * HELPERS
 * ============================================================ */

/**
 * Xây mệnh đề WHERE cho query nghi_phep tùy theo vai trò.
 * Trả về [whereSql, paramsArray]
 */
function buildLeavesWhere(string $role, int $nhanVienId, ?array $emp): array
{
    $where  = '';
    $params = [];

    /* Admin / Giám đốc / Nhân sự → xem tất cả */
    if (in_array($role, ['admin', 'giam_doc', 'giamdoc', 'ban_lanh_dao', 'nhan_su', 'nhansu'], true)) {
        return ['', []];
    }

    /* Trưởng khoa → xem theo khoa */
    if (in_array($role, ['truong_khoa', 'truongkhoa'], true)) {
        $khoaId = (int)($emp['khoa_phong_id'] ?? 0);
        if ($khoaId > 0) {
            return ['WHERE nv.khoa_phong_id = ?', [$khoaId]];
        }
        /* Không có khoa_phong_id → fallback xem của mình */
    }

    /* Nhân viên (mặc định) → chỉ xem đơn của mình */
    return ['WHERE np.nhan_vien_id = ?', [$nhanVienId]];
}

/**
 * Tính stats phép năm cho nhân viên
 */
function buildStats(int $nhanVienId, int $year): array
{
    $stats = [
        'tong_phep'    => 12,
        'da_su_dung'   => 0,
        'phep_con_lai' => 12,
        'cho_duyet'    => 0,
        'da_duyet'     => 0,
        'tu_choi'      => 0,
    ];

    if ($nhanVienId <= 0) {
        return $stats;
    }

    /* 1. Lấy hạn mức phép */
    try {
        if (function_exists('getEmployeeLeaveQuota')) {
            $quota = getEmployeeLeaveQuota($nhanVienId, $year);
            if (!empty($quota)) {
                $stats['tong_phep']    = (int)($quota['tong_phep']    ?? 12);
                $stats['da_su_dung']   = (int)($quota['so_ngay_da_nghi'] ?? 0);
                $stats['phep_con_lai'] = (int)($quota['phep_con_lai'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        error_log('[TrangChu] Quota error: ' . $e->getMessage());
    }

    /* 2. Đếm số đơn theo trạng thái trong năm */
    try {
        $db = Database::getConnection();

        $stChoTruongKhoa = defined('STATUS_CHO_TRUONG_KHOA') ? STATUS_CHO_TRUONG_KHOA : 'cho_truong_khoa';
        $stChoPheDuyet   = defined('STATUS_CHO_PHE_DUYET')   ? STATUS_CHO_PHE_DUYET   : 'cho_phe_duyet';
        $stDaDuyet       = defined('STATUS_DA_DUYET')        ? STATUS_DA_DUYET        : 'da_duyet';
        $stDaTiepNhan    = defined('STATUS_DA_TIEP_NHAN')    ? STATUS_DA_TIEP_NHAN    : 'da_tiep_nhan';
        $stTuChoi        = defined('STATUS_TU_CHOI')         ? STATUS_TU_CHOI         : 'tu_choi';

        $stmt = $db->prepare("
            SELECT
                SUM(CASE WHEN trang_thai IN (?, ?) THEN 1 ELSE 0 END) AS cho_duyet,
                SUM(CASE WHEN trang_thai IN (?, ?) THEN 1 ELSE 0 END) AS da_duyet,
                SUM(CASE WHEN trang_thai = ?       THEN 1 ELSE 0 END) AS tu_choi
            FROM nghi_phep
            WHERE nhan_vien_id = ?
              AND YEAR(tu_ngay) = ?
        ");
        $stmt->execute([
            $stChoTruongKhoa, $stChoPheDuyet,
            $stDaDuyet,       $stDaTiepNhan,
            $stTuChoi,
            $nhanVienId,
            $year,
        ]);
        $row = $stmt->fetch();

        if ($row) {
            $stats['cho_duyet'] = (int)($row['cho_duyet'] ?? 0);
            $stats['da_duyet']  = (int)($row['da_duyet']  ?? 0);
            $stats['tu_choi']   = (int)($row['tu_choi']   ?? 0);
        }
    } catch (Throwable $e) {
        error_log('[TrangChu] Stats error: ' . $e->getMessage());
    }

    return $stats;
}

/**
 * Chuẩn hóa 1 dòng nghi_phep trước khi trả JSON
 */
function normalizeLeaveRow(array $row): array
{
    return [
        'id'         => (int)($row['id'] ?? 0),
        'ma_don'     => $row['ma_don']     ?? '',
        'ho_ten'     => $row['ho_ten']     ?? '',
        'chuc_vu'    => $row['chuc_vu']    ?? '',
        'ma_nv'      => $row['ma_nv']      ?? '',
        'ten_khoa'   => $row['ten_khoa']   ?? '',
        'tu_ngay'    => $row['tu_ngay']    ?? null,
        'den_ngay'   => $row['den_ngay']   ?? null,
        'so_ngay'    => (float)($row['so_ngay'] ?? 0),
        'trang_thai' => $row['trang_thai'] ?? 'draft',
        'created_at' => $row['created_at'] ?? null,
    ];
}

/**
 * Map vai trò → URL redirect (giống dangnhap.php)
 */
function getRedirectUrlByRole(string $role): string
{
    $base = rtrim(BASE_URL, '/') . '/frontend';

    $map = [
        'admin'        => $base . '/admin/index.html',
        'giam_doc'     => $base . '/banlanhdao/index.html',
        'giamdoc'      => $base . '/banlanhdao/index.html',
        'ban_lanh_dao' => $base . '/banlanhdao/index.html',
        'truong_khoa'  => $base . '/nhanvien/index.html',
        'truongkhoa'   => $base . '/nhanvien/index.html',
        'nhan_su'      => $base . '/nhansutiepnhan/index.html',
        'nhansu'       => $base . '/nhansutiepnhan/index.html',
        'nhan_vien'    => $base . '/nhanvien/index.html',
        'nhanvien'     => $base . '/nhanvien/index.html',
    ];

    return $map[$role] ?? $base . '/home/trangchu.html';
}