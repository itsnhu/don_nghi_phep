<?php
/**
 * ============================================================
 *  API: DỮ LIỆU IN GIẤY NGHỈ PHÉP KHỔ A4
 *  Endpoint: backend/api/in.php
 *  Method: GET
 *  Params: ?id=<nghi_phep_id>
 *  Response: JSON
 * ============================================================
 */

/* 1. OUTPUT BUFFERING */
if (!ob_get_level()) { ob_start(); }

/* 2. LOAD CONFIG TRƯỚC (để dùng đúng session_name/cookie_path) */
$configFile = __DIR__ . '/../config/app.php';
if (!file_exists($configFile)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'config_missing'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $configFile;
require_once __DIR__ . '/../config/ketnoisql.php';
require_once __DIR__ . '/../config/functions.php';

/* 3. SESSION */
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.cookie_httponly', 1);
    @ini_set('session.use_only_cookies', 1);
    session_start();
}

/* 4. JSON HEADERS */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* 5. FALLBACK CONSTANTS */
if (!defined('ROLE_ADMIN'))       define('ROLE_ADMIN',       'admin');
if (!defined('ROLE_NHAN_VIEN'))   define('ROLE_NHAN_VIEN',   'nhan_vien');
if (!defined('ROLE_TRUONG_KHOA')) define('ROLE_TRUONG_KHOA', 'truong_khoa');
if (!defined('ROLE_LANH_DAO'))    define('ROLE_LANH_DAO',    'lanh_dao');
if (!defined('ROLE_NHAN_SU'))     define('ROLE_NHAN_SU',     'nhan_su');
if (!defined('CURRENT_YEAR'))     define('CURRENT_YEAR', (int)date('Y'));
if (!defined('HOSPITAL_NAME'))    define('HOSPITAL_NAME', 'BỆNH VIỆN TÂM TRÍ ĐỒNG THÁP');
if (!defined('HOSPITAL_DIRECTOR')) define('HOSPITAL_DIRECTOR', 'ThS. BS. Đinh Tấn Tài');

/* 6. HELPER JSON */
if (!function_exists('jsonResponse')) {
    function jsonResponse(array $payload, int $httpCode = 200): void {
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if (!function_exists('jsonSuccess')) {
    function jsonSuccess(array $data = [], string $message = ''): void {
        jsonResponse(['success' => true, 'message' => $message, 'data' => $data]);
    }
}
if (!function_exists('jsonError')) {
    function jsonError(string $message, int $code = 400, string $error = ''): void {
        jsonResponse(['success' => false, 'error' => $error ?: 'error', 'message' => $message], $code);
    }
}

/* 7. AUTH */
if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool {
        return isset($_SESSION['user']) && !empty($_SESSION['user']['id']);
    }
}
if (!isLoggedIn()) {
    jsonError('Vui lòng đăng nhập để tiếp tục!', 401, 'unauthorized');
}

$currentUser   = $_SESSION['user'];
$userRole      = $currentUser['vai_tro'] ?? '';
$currentEmpId  = $currentUser['nhan_vien_id'] ?? 0;
$currentUserId = $currentUser['id'] ?? 0;

/* 8. HELPER FALLBACK */
if (!function_exists('getCurrentEmployee')) {
    function getCurrentEmployee(): ?array {
        global $currentUser;
        if (empty($currentUser['nhan_vien_id'])) return null;
        $db = Database::getConnection();
        $stmt = $db->prepare('
            SELECT nv.*, kp.ten_khoa, kp.ma_khoa
            FROM nhan_vien nv
            LEFT JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
            WHERE nv.id = ?
        ');
        $stmt->execute([$currentUser['nhan_vien_id']]);
        return $stmt->fetch() ?: null;
    }
}
if (!function_exists('formatDate')) {
    function formatDate(?string $s): string {
        if (empty($s) || $s === '0000-00-00') return '-';
        $t = strtotime($s);
        return $t ? date('d/m/Y', $t) : '-';
    }
}
if (!function_exists('getBuoiNghiText')) {
    function getBuoiNghiText(string $buoi): string {
        return match ($buoi) {
            'sang'    => 'Buổi sáng (0.5 ngày)',
            'chieu'   => 'Buổi chiều (0.5 ngày)',
            'ca_ngay' => 'Nguyên ngày',
            default   => 'Nguyên ngày'
        };
    }
}
if (!function_exists('getEmployeeLeaveQuota')) {
    function getEmployeeLeaveQuota(int $nhanVienId, ?int $year = null): array {
        $year = $year ?? CURRENT_YEAR;
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM phep_nam WHERE nhan_vien_id = ? AND nam = ?');
        $stmt->execute([$nhanVienId, $year]);
        $quota = $stmt->fetch();
        if (!$quota) {
            $stmtInsert = $db->prepare('
                INSERT INTO phep_nam (nhan_vien_id, nam, phep_nam, phep_tham_nien, phep_chuyen_sang, so_ngay_da_nghi)
                VALUES (?, ?, 12.0, 0.0, 0.0, 0.0)
            ');
            $stmtInsert->execute([$nhanVienId, $year]);
            $quota = ['phep_nam'=>12.0,'phep_tham_nien'=>0.0,'phep_chuyen_sang'=>0.0,'so_ngay_da_nghi'=>0.0];
        }
        $tongPhep = (float)$quota['phep_nam'] + (float)$quota['phep_tham_nien'] + (float)$quota['phep_chuyen_sang'];
        $daNghi   = (float)$quota['so_ngay_da_nghi'];
        return [
            'phep_nam'         => (float)$quota['phep_nam'],
            'phep_tham_nien'   => (float)$quota['phep_tham_nien'],
            'phep_chuyen_sang' => (float)$quota['phep_chuyen_sang'],
            'tong_phep'        => $tongPhep,
            'so_ngay_da_nghi'  => $daNghi,
            'phep_con_lai'     => max(0, $tongPhep - $daNghi),
        ];
    }
}

/* 9. ĐỌC INPUT */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonError('Mã giấy nghỉ phép không hợp lệ!', 400, 'invalid_id');
}

$db = Database::getConnection();

/* 10. LẤY ĐƠN HIỆN TẠI */
$stmt = $db->prepare('
    SELECT np.*, nv.id AS nhan_vien_id, nv.ho_ten, nv.ma_nv, nv.chuc_vu, kp.ten_khoa
    FROM nghi_phep np
    JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
    JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
    WHERE np.id = ?
');
$stmt->execute([$id]);
$currentLeave = $stmt->fetch();

if (!$currentLeave) {
    jsonError('Không tìm thấy dữ liệu giấy nghỉ phép!', 404, 'not_found');
}

/* 11. PHÂN QUYỀN — nhân viên chỉ được in đơn của mình */
if ($userRole === ROLE_NHAN_VIEN && (int)$currentLeave['nhan_vien_id'] !== (int)$currentEmpId) {
    jsonError('Bạn không có quyền in giấy nghỉ phép của nhân viên khác!', 403, 'forbidden');
}

$nhanVienId = (int)$currentLeave['nhan_vien_id'];
$year       = (int)date('Y', strtotime($currentLeave['tu_ngay']));
$prevYear   = $year - 1;

/* 12. TÌM NGƯỜI "CẤP CHO" (Trưởng khoa có ý kiến) */
$capChoHoTen = '';
$capChoChucVu = '';
$nguoiChoYKienId = $currentLeave['nguoi_cho_y_kien'] ?? null;

if (empty($nguoiChoYKienId)) {
    $stmtFallback = $db->prepare('
        SELECT nguoi_cho_y_kien FROM nghi_phep
        WHERE nhan_vien_id = ? AND YEAR(tu_ngay) = ? AND nguoi_cho_y_kien IS NOT NULL
        ORDER BY ngay_cho_y_kien DESC LIMIT 1
    ');
    $stmtFallback->execute([$nhanVienId, $year]);
    $nguoiChoYKienId = $stmtFallback->fetchColumn();
}

if (!empty($nguoiChoYKienId)) {
    $stmtTK = $db->prepare('
        SELECT nv.ho_ten, nv.chuc_vu
        FROM users u
        JOIN nhan_vien nv ON u.nhan_vien_id = nv.id
        WHERE u.id = ?
    ');
    $stmtTK->execute([$nguoiChoYKienId]);
    $truongKhoa = $stmtTK->fetch();
    if ($truongKhoa) {
        $capChoHoTen  = $truongKhoa['ho_ten'];
        $capChoChucVu = $truongKhoa['chuc_vu'];
    }
}

if (empty($capChoHoTen)) {
    $stmtKhoa = $db->prepare('
        SELECT nv.ho_ten, nv.chuc_vu
        FROM users u
        JOIN nhan_vien nv ON u.nhan_vien_id = nv.id
        WHERE u.vai_tro = "truong_khoa" AND nv.khoa_phong_id = (
            SELECT khoa_phong_id FROM nhan_vien WHERE id = ?
        )
        LIMIT 1
    ');
    $stmtKhoa->execute([$nhanVienId]);
    $truongKhoaFallback = $stmtKhoa->fetch();
    if ($truongKhoaFallback) {
        $capChoHoTen  = $truongKhoaFallback['ho_ten'];
        $capChoChucVu = $truongKhoaFallback['chuc_vu'];
    }
}

/* 13. QUOTA NĂM */
$quota = getEmployeeLeaveQuota($nhanVienId, $year);

/* 14. TẤT CẢ ĐƠN TRONG NĂM */
$stmtAllLeaves = $db->prepare('
    SELECT * FROM nghi_phep
    WHERE nhan_vien_id = ? AND YEAR(tu_ngay) = ?
    ORDER BY tu_ngay ASC
');
$stmtAllLeaves->execute([$nhanVienId, $year]);
$allLeaves = $stmtAllLeaves->fetchAll();

/* 15. SỐ DÒNG TỐI THIỂU */
$totalSlots = max(8, count($allLeaves));

/* ============================================================
   15b. BUILD MAP user_id → họ tên (cho trưởng khoa + lãnh đạo)
   ⭐ THÊM MỚI — để render text tên dưới chữ ký trong file in
   ============================================================ */
$userIds = [];
foreach ($allLeaves as $it) {
    if (!empty($it['nguoi_cho_y_kien'])) $userIds[] = (int)$it['nguoi_cho_y_kien'];
    if (!empty($it['nguoi_phe_duyet']))  $userIds[] = (int)$it['nguoi_phe_duyet'];
}
$userIds = array_values(array_unique($userIds));

$userNameMap = [];
if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    try {
        $stmtU = $db->prepare("
            SELECT u.id, nv.ho_ten
            FROM users u
            LEFT JOIN nhan_vien nv ON u.nhan_vien_id = nv.id
            WHERE u.id IN ($placeholders)
        ");
        $stmtU->execute($userIds);
        while ($r = $stmtU->fetch()) {
            $userNameMap[(int)$r['id']] = $r['ho_ten'] ?? '';
        }
    } catch (Exception $e) {
        error_log('in.php nameMap error: ' . $e->getMessage());
    }
}

/* 16. CHUẨN HÓA DỮ LIỆU TRẢ VỀ */
$leaveData = [
    'id'          => (int)$currentLeave['id'],
    'ma_don'      => $currentLeave['ma_don'] ?? '',
    'ho_ten'      => $currentLeave['ho_ten'] ?? '',
    'ma_nv'       => $currentLeave['ma_nv'] ?? '',
    'chuc_vu'     => $currentLeave['chuc_vu'] ?? '',
    'ten_khoa'    => $currentLeave['ten_khoa'] ?? '',
    'tu_ngay'     => $currentLeave['tu_ngay'] ?? null,
    'den_ngay'    => $currentLeave['den_ngay'] ?? null,
];

/* ⭐ CẬP NHẬT: thêm 3 field tên người ký cho từng dòng */
$allLeavesData = array_map(function ($it) use ($currentLeave, $userNameMap) {
    return [
        'id'                       => (int)$it['id'],
        'tu_ngay'                  => $it['tu_ngay'] ?? null,
        'den_ngay'                 => $it['den_ngay'] ?? null,
        'buoi_nghi'                => $it['buoi_nghi'] ?? 'ca_ngay',
        'buoi_nghi_text'           => getBuoiNghiText($it['buoi_nghi'] ?? 'ca_ngay'),
        'ly_do'                    => $it['ly_do'] ?? '',
        'chu_ky_nguoi_nghi'        => $it['chu_ky_nguoi_nghi'] ?? null,
        'chu_ky_truong_khoa'       => $it['chu_ky_truong_khoa'] ?? null,
        'chu_ky_lanh_dao'          => $it['chu_ky_lanh_dao'] ?? null,
        'so_ngay'                  => (float)($it['so_ngay'] ?? 0),
        'so_phep_con_lai_luc_nghi' => $it['so_phep_con_lai_luc_nghi'] !== null
                                        ? (float)$it['so_phep_con_lai_luc_nghi'] : null,
        'ngay_nop_nhan_su'         => $it['ngay_nop_nhan_su'] ?? null,
        'ngay_nop'                 => $it['ngay_nop'] ?? null,

        /* ⭐ MỚI: Tên người ký cho từng cột */
        'ten_nguoi_nghi'  => $currentLeave['ho_ten'] ?? '',
        'ten_truong_khoa' => $userNameMap[(int)($it['nguoi_cho_y_kien'] ?? 0)] ?? '',
        'ten_lanh_dao'    => $userNameMap[(int)($it['nguoi_phe_duyet']  ?? 0)] ?? '',
    ];
}, $allLeaves);

/* 17. USER INFO CHO FE */
$currentEmpForFe = getCurrentEmployee();
$userForFe = [
    'id'           => (int)$currentUserId,
    'username'     => $currentUser['username'] ?? '',
    'vai_tro'      => $userRole,
    'nhan_vien_id' => $currentEmpId ?: null,
    'ho_ten'       => $currentEmpForFe['ho_ten'] ?? $currentUser['username'] ?? 'Người dùng',
];

/* 18. RESPONSE */
jsonSuccess([
    'user'              => $userForFe,
    'leave'             => $leaveData,
    'cap_cho'           => [
        'ho_ten'  => $capChoHoTen,
        'chuc_vu' => $capChoChucVu,
    ],
    'quota'             => $quota,
    'all_leaves'        => $allLeavesData,
    'year'              => $year,
    'prev_year'         => $prevYear,
    'hospital_name'     => HOSPITAL_NAME,
    'hospital_director' => HOSPITAL_DIRECTOR,
    'total_slots'       => $totalSlots,
]);