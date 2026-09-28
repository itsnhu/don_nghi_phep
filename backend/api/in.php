<?php
/**
 * ============================================================
 *  API: DỮ LIỆU IN GIẤY NGHỈ PHÉP KHỔ A4
 *  Endpoint: backend/api/in.php
 *  Method: GET
 *  Params: ?id=<nghi_phep_id>
 *  Response: JSON
 *
 *  ⭐ QUY TẮC:
 *   - Chỉ in đơn đã xác nhận (da_duyet / da_tiep_nhan)
 *   - Tối đa 10 dòng / 1 mặt giấy A4
 *   - Lấy 10 đơn MỚI NHẤT (theo tu_ngay DESC, id DESC)
 *   - Hiển thị theo thứ tự thời gian tăng dần
 * ============================================================
 */

/* 1. OUTPUT BUFFERING */
if (!ob_get_level()) { ob_start(); }

/* 2. LOAD CONFIG */
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

/* ⭐ Trạng thái được phép in */
if (!defined('STATUS_IN_PRINTABLE')) {
    define('STATUS_IN_PRINTABLE', ['da_duyet', 'da_tiep_nhan']);
}

/* ⭐ Số dòng tối đa / mặt giấy A4 */
if (!defined('MAX_ROWS_PER_PAGE')) define('MAX_ROWS_PER_PAGE', 10);

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

/* ============================================================
   8.1 HELPER CA NGHỈ
   ============================================================ */
if (!function_exists('normalizeCaNghiDb')) {
    function normalizeCaNghiDb(?string $ca): string {
        $valid = ['ca_ngay', 'sang', 'chieu'];
        if ($ca === null || $ca === '') return 'ca_ngay';
        return in_array($ca, $valid, true) ? $ca : 'ca_ngay';
    }
}
if (!function_exists('caNghiLabel')) {
    function caNghiLabel(?string $ca): string {
        $map = ['ca_ngay' => 'Cả ngày', 'sang' => 'Buổi sáng', 'chieu' => 'Buổi chiều'];
        return $map[normalizeCaNghiDb($ca)] ?? 'Cả ngày';
    }
}
if (!function_exists('isCaNghiSingle')) {
    function isCaNghiSingle(?string $start, ?string $end): bool {
        return normalizeCaNghiDb($start) === normalizeCaNghiDb($end);
    }
}
if (!function_exists('diffDaysInclusive')) {
    function diffDaysInclusive(?string $tuNgay, ?string $denNgay): int {
        if (empty($tuNgay) || empty($denNgay)) return 1;
        $t1 = strtotime($tuNgay);
        $t2 = strtotime($denNgay);
        if ($t1 === false || $t2 === false) return 1;
        return (int)floor(($t2 - $t1) / 86400) + 1;
    }
}
if (!function_exists('getBuoiNghiText')) {
    function getBuoiNghiText(?string $buoiStart, ?string $buoiEnd = null): string {
        $s = normalizeCaNghiDb($buoiStart);
        $e = normalizeCaNghiDb($buoiEnd !== null ? $buoiEnd : $s);
        if ($s === $e) {
            if ($s === 'ca_ngay') return '';
            return caNghiLabel($s) . ' (0.5 ngày)';
        }
        return caNghiLabel($s) . ' ngày đầu → ' . caNghiLabel($e) . ' ngày cuối';
    }
}
if (!function_exists('caNghiShortLabel')) {
    function caNghiShortLabel(?string $buoiStart, ?string $buoiEnd = null): string {
        $s = normalizeCaNghiDb($buoiStart);
        $e = normalizeCaNghiDb($buoiEnd !== null ? $buoiEnd : $s);
        if ($s === $e) return caNghiLabel($s);
        return caNghiLabel($s) . ' → ' . caNghiLabel($e);
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

/* 11. PHÂN QUYỀN */
if ($userRole === ROLE_NHAN_VIEN && (int)$currentLeave['nhan_vien_id'] !== (int)$currentEmpId) {
    jsonError('Bạn không có quyền in giấy nghỉ phép của nhân viên khác!', 403, 'forbidden');
}

$nhanVienId = (int)$currentLeave['nhan_vien_id'];
$year       = (int)date('Y', strtotime($currentLeave['tu_ngay']));
$prevYear   = $year - 1;

/* 12. TÌM NGƯỜI "CẤP CHO" */
$capChoHoTen  = '';
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

/* ============================================================
   14. ĐẾM TỔNG ĐƠN ĐÃ XÁC NHẬN TRONG NĂM
   ============================================================ */
$placeholders = implode(',', array_fill(0, count(STATUS_IN_PRINTABLE), '?'));

$stmtCount = $db->prepare("
    SELECT COUNT(*) FROM nghi_phep
    WHERE nhan_vien_id = ?
      AND YEAR(tu_ngay) = ?
      AND trang_thai IN ($placeholders)
");
$stmtCount->execute(array_merge([$nhanVienId, $year], STATUS_IN_PRINTABLE));
$totalConfirmed = (int)$stmtCount->fetchColumn();

/* ============================================================
   ⭐ 15. LẤY 10 ĐƠN MỚI NHẤT
   ------------------------------------------------------------
   - ORDER BY tu_ngay DESC, id DESC → lấy mới nhất trước
   - LIMIT 10 → giới hạn 10 dòng / 1 mặt giấy
   ============================================================ */
$limitRows = MAX_ROWS_PER_PAGE;

$stmtAllLeaves = $db->prepare("
    SELECT * FROM nghi_phep
    WHERE nhan_vien_id = ?
      AND YEAR(tu_ngay) = ?
      AND trang_thai IN ($placeholders)
    ORDER BY tu_ngay DESC, id DESC
    LIMIT $limitRows
");
$stmtAllLeaves->execute(array_merge([$nhanVienId, $year], STATUS_IN_PRINTABLE));
$rawLeaves = $stmtAllLeaves->fetchAll();

/* ⭐ Đảo ngược để hiển thị theo thứ tự thời gian tăng dần (cũ → mới) */
$allLeaves = array_reverse($rawLeaves);

/* ⭐ Số dòng cố định = 10 (đủ 10 dòng/mặt giấy) */
$totalSlots = MAX_ROWS_PER_PAGE;

/* Thông tin thống kê */
$wasCut        = $totalConfirmed > MAX_ROWS_PER_PAGE;
$printedCount  = count($allLeaves);
$totalDaysPrint = 0.0;
foreach ($allLeaves as $lv) {
    $totalDaysPrint += (float)($lv['so_ngay'] ?? 0);
}

/* ============================================================
   15b. BUILD MAP user_id → họ tên
   ============================================================ */
$userIds = [];
foreach ($allLeaves as $it) {
    if (!empty($it['nguoi_cho_y_kien'])) $userIds[] = (int)$it['nguoi_cho_y_kien'];
    if (!empty($it['nguoi_phe_duyet']))  $userIds[] = (int)$it['nguoi_phe_duyet'];
}
$userIds = array_values(array_unique($userIds));

$userNameMap = [];
if (!empty($userIds)) {
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    try {
        $stmtU = $db->prepare("
            SELECT u.id, nv.ho_ten
            FROM users u
            LEFT JOIN nhan_vien nv ON u.nhan_vien_id = nv.id
            WHERE u.id IN ($ph)
        ");
        $stmtU->execute($userIds);
        while ($r = $stmtU->fetch()) {
            $userNameMap[(int)$r['id']] = $r['ho_ten'] ?? '';
        }
    } catch (Exception $e) {
        error_log('in.php nameMap error: ' . $e->getMessage());
    }
}

/* ============================================================
   16. CHUẨN HÓA ĐƠN HIỆN TẠI
   ============================================================ */
$currentCaStart = normalizeCaNghiDb($currentLeave['buoi_nghi']          ?? 'ca_ngay');
$currentCaEnd   = normalizeCaNghiDb($currentLeave['buoi_nghi_ket_thuc'] ?? $currentCaStart);

$leaveData = [
    'id'                 => (int)$currentLeave['id'],
    'ma_don'             => $currentLeave['ma_don'] ?? '',
    'ho_ten'             => $currentLeave['ho_ten'] ?? '',
    'ma_nv'              => $currentLeave['ma_nv'] ?? '',
    'chuc_vu'            => $currentLeave['chuc_vu'] ?? '',
    'ten_khoa'           => $currentLeave['ten_khoa'] ?? '',
    'tu_ngay'            => $currentLeave['tu_ngay'] ?? null,
    'den_ngay'           => $currentLeave['den_ngay'] ?? null,
    'trang_thai'         => $currentLeave['trang_thai'] ?? '',
    'is_printable'       => in_array($currentLeave['trang_thai'] ?? '', STATUS_IN_PRINTABLE, true),

    'buoi_nghi'          => $currentCaStart,
    'buoi_nghi_ket_thuc' => $currentCaEnd,
    'buoi_nghi_text'     => getBuoiNghiText($currentCaStart, $currentCaEnd),
    'ca_nghi_label'      => caNghiShortLabel($currentCaStart, $currentCaEnd),
];

/* ============================================================
   17. CHUẨN HÓA TẤT CẢ ĐƠN
   ============================================================ */
$allLeavesData = array_map(function ($it) use ($currentLeave, $userNameMap) {

    $caStart = normalizeCaNghiDb($it['buoi_nghi']          ?? 'ca_ngay');
    $caEnd   = normalizeCaNghiDb($it['buoi_nghi_ket_thuc'] ?? $caStart);

    return [
        'id'                       => (int)$it['id'],
        'ma_don'                   => $it['ma_don'] ?? '',
        'tu_ngay'                  => $it['tu_ngay'] ?? null,
        'den_ngay'                 => $it['den_ngay'] ?? null,
        'trang_thai'               => $it['trang_thai'] ?? '',

        'buoi_nghi'                => $caStart,
        'buoi_nghi_ket_thuc'       => $caEnd,
        'buoi_nghi_text'           => getBuoiNghiText($caStart, $caEnd),
        'ca_nghi_label'            => caNghiShortLabel($caStart, $caEnd),
        'total_days_range'         => diffDaysInclusive($it['tu_ngay'] ?? null, $it['den_ngay'] ?? null),
        'is_nghi_nua_buoi'         => isCaNghiSingle($caStart, $caEnd) && $caStart !== 'ca_ngay',

        'ly_do'                    => $it['ly_do'] ?? '',
        'chu_ky_nguoi_nghi'        => $it['chu_ky_nguoi_nghi'] ?? null,
        'chu_ky_truong_khoa'       => $it['chu_ky_truong_khoa'] ?? null,
        'chu_ky_lanh_dao'          => $it['chu_ky_lanh_dao'] ?? null,
        'so_ngay'                  => (float)($it['so_ngay'] ?? 0),
        'so_phep_con_lai_luc_nghi' => $it['so_phep_con_lai_luc_nghi'] !== null
                                        ? (float)$it['so_phep_con_lai_luc_nghi'] : null,
        'ngay_nop_nhan_su'         => $it['ngay_nop_nhan_su'] ?? null,
        'ngay_nop'                 => $it['ngay_nop'] ?? null,

        'ten_nguoi_nghi'  => $currentLeave['ho_ten'] ?? '',
        'ten_truong_khoa' => $userNameMap[(int)($it['nguoi_cho_y_kien'] ?? 0)] ?? '',
        'ten_lanh_dao'    => $userNameMap[(int)($it['nguoi_phe_duyet']  ?? 0)] ?? '',
    ];
}, $allLeaves);

/* 18. USER INFO CHO FE */
$currentEmpForFe = getCurrentEmployee();
$userForFe = [
    'id'           => (int)$currentUserId,
    'username'     => $currentUser['username'] ?? '',
    'vai_tro'      => $userRole,
    'nhan_vien_id' => $currentEmpId ?: null,
    'ho_ten'       => $currentEmpForFe['ho_ten'] ?? $currentUser['username'] ?? 'Người dùng',
];

/* ============================================================
   19. RESPONSE
   ============================================================ */
jsonSuccess([
    'user'    => $userForFe,
    'leave'   => $leaveData,
    'cap_cho' => [
        'ho_ten'  => $capChoHoTen,
        'chuc_vu' => $capChoChucVu,
    ],
    'quota'   => $quota,
    'all_leaves' => $allLeavesData,

    /* ⭐ Thống kê mới */
    'stats'   => [
        'total_confirmed_leaves' => $totalConfirmed,         // tổng đơn đã xác nhận
        'total_printed_leaves'   => $printedCount,           // số đơn in ra
        'total_days_printed'     => round($totalDaysPrint, 1),
        'max_rows_per_page'      => MAX_ROWS_PER_PAGE,       // = 10
        'was_cut'                => $wasCut,                 // có bị cắt không
    ],

    'year'              => $year,
    'prev_year'         => $prevYear,
    'hospital_name'     => HOSPITAL_NAME,
    'hospital_director' => HOSPITAL_DIRECTOR,
    'total_slots'       => $totalSlots,                       // = 10 (cố định)
]);