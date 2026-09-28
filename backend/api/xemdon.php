<?php
/**
 * ============================================================
 *  API: XEM CHI TIẾT GIẤY NGHỈ PHÉP & THAO TÁC PHÊ DUYỆT
 *  Endpoint: backend/api/xemdon.php
 *  Method: GET (lấy chi tiết) | POST (duyệt/từ chối/tiếp nhận)
 *  Response: JSON
 * ============================================================
 */

/* ============================================================
   1. OUTPUT BUFFERING
   ============================================================ */
if (!ob_get_level()) { ob_start(); }

/* ============================================================
   2. LOAD CONFIG (PHẢI CHẠY TRƯỚC SESSION_START)
   ============================================================ */
$configFile = __DIR__ . '/../config/app.php';
if (!file_exists($configFile)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'config_missing', 'message' => 'Không tìm thấy file cấu hình.'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $configFile;
require_once __DIR__ . '/../config/ketnoisql.php';
require_once __DIR__ . '/../config/functions.php';

/* ============================================================
   3. SESSION
   ============================================================ */
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.cookie_httponly', 1);
    @ini_set('session.use_only_cookies', 1);
    session_start();
}

/* ============================================================
   4. JSON HEADERS
   ============================================================ */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ============================================================
   5. FALLBACK CONSTANTS
   ============================================================ */
if (!defined('ROLE_ADMIN'))       define('ROLE_ADMIN',       'admin');
if (!defined('ROLE_NHAN_VIEN'))   define('ROLE_NHAN_VIEN',   'nhan_vien');
if (!defined('ROLE_TRUONG_KHOA')) define('ROLE_TRUONG_KHOA', 'truong_khoa');
if (!defined('ROLE_LANH_DAO'))    define('ROLE_LANH_DAO',    'lanh_dao');
if (!defined('ROLE_NHAN_SU'))     define('ROLE_NHAN_SU',     'nhan_su');

if (!defined('STATUS_DRAFT'))           define('STATUS_DRAFT',           'draft');
if (!defined('STATUS_CHO_TRUONG_KHOA')) define('STATUS_CHO_TRUONG_KHOA', 'cho_truong_khoa');
if (!defined('STATUS_CHO_PHE_DUYET'))   define('STATUS_CHO_PHE_DUYET',   'cho_phe_duyet');
if (!defined('STATUS_DA_DUYET'))        define('STATUS_DA_DUYET',        'da_duyet');
if (!defined('STATUS_TU_CHOI'))         define('STATUS_TU_CHOI',         'tu_choi');
if (!defined('STATUS_DA_TIEP_NHAN'))    define('STATUS_DA_TIEP_NHAN',    'da_tiep_nhan');

if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('BASE_URL', $protocol . '://' . $host);
}
if (!defined('CURRENT_YEAR')) define('CURRENT_YEAR', (int)date('Y'));

/* ============================================================
   6. HELPER: JSON RESPONSE
   ============================================================ */
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

/* ============================================================
   7. AUTH GUARD
   ============================================================ */
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

/* ============================================================
   8. HELPER FUNCTIONS (fallback)
   ============================================================ */
if (!function_exists('hasRole')) {
    function hasRole(array|string $roles): bool {
        global $userRole;
        if ($userRole === ROLE_ADMIN) return true;
        if (is_string($roles)) $roles = [$roles];
        return in_array($userRole, $roles, true);
    }
}

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

if (!function_exists('formatDateTime')) {
    function formatDateTime(?string $s): string {
        if (empty($s) || $s === '0000-00-00 00:00:00') return '-';
        $t = strtotime($s);
        return $t ? date('d/m/Y H:i', $t) : '-';
    }
}

if (!function_exists('recordLeaveHistory')) {
    function recordLeaveHistory(int $nghiPhepId, int $userId, string $thaoTac, ?string $oldStatus, string $newStatus, ?string $note = null): bool {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('
                INSERT INTO nghi_phep_lich_su (nghi_phep_id, user_id, thao_tac, trang_thai_cu, trang_thai_moi, ghi_chu)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            return $stmt->execute([$nghiPhepId, $userId, $thaoTac, $oldStatus, $newStatus, $note]);
        } catch (Exception $e) {
            error_log('recordLeaveHistory: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('createNotification')) {
    function createNotification(int $userId, string $title, string $content, ?string $link = null): bool {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('
                INSERT INTO notifications (user_id, tieu_de, noi_dung, link)
                VALUES (?, ?, ?, ?)
            ');
            return $stmt->execute([$userId, $title, $content, $link]);
        } catch (Exception $e) {
            error_log('createNotification: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('deductLeaveDaysSecure')) {
    function deductLeaveDaysSecure(int $nghiPhepId, int $nhanVienId, float $soNgay, int $year): bool {
        $db = Database::getConnection();

        $stmtLock = $db->prepare('
            SELECT * FROM phep_nam
            WHERE nhan_vien_id = ? AND nam = ?
            FOR UPDATE
        ');
        $stmtLock->execute([$nhanVienId, $year]);
        $quota = $stmtLock->fetch();

        if (!$quota) {
            $stmtIns = $db->prepare('
                INSERT INTO phep_nam (nhan_vien_id, nam, phep_nam, phep_tham_nien, phep_chuyen_sang, so_ngay_da_nghi)
                VALUES (?, ?, 12.0, 0.0, 0.0, 0.0)
            ');
            $stmtIns->execute([$nhanVienId, $year]);
            $quota = ['phep_nam' => 12.0, 'phep_tham_nien' => 0.0, 'phep_chuyen_sang' => 0.0, 'so_ngay_da_nghi' => 0.0];
        }

        $tongPhep  = (float)$quota['phep_nam'] + (float)$quota['phep_tham_nien'] + (float)$quota['phep_chuyen_sang'];
        $newDaNghi = (float)$quota['so_ngay_da_nghi'] + $soNgay;
        $newConLai = max(0, $tongPhep - $newDaNghi);

        $stmtUpPhep = $db->prepare('
            UPDATE phep_nam
            SET so_ngay_da_nghi = ?
            WHERE nhan_vien_id = ? AND nam = ?
        ');
        $stmtUpPhep->execute([$newDaNghi, $nhanVienId, $year]);

        $stmtUpDon = $db->prepare('
            UPDATE nghi_phep
            SET da_tru_phep = 1, so_phep_con_lai_luc_nghi = ?
            WHERE id = ?
        ');
        $stmtUpDon->execute([$newConLai, $nghiPhepId]);

        return true;
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

            $quota = [
                'id'               => (int)$db->lastInsertId(),
                'nhan_vien_id'     => $nhanVienId,
                'nam'              => $year,
                'phep_nam'         => 12.0,
                'phep_tham_nien'   => 0.0,
                'phep_chuyen_sang' => 0.0,
                'so_ngay_da_nghi'  => 0.0,
            ];
        }

        $tongPhep = (float)$quota['phep_nam'] + (float)$quota['phep_tham_nien'] + (float)$quota['phep_chuyen_sang'];
        $daNghi   = (float)$quota['so_ngay_da_nghi'];
        $conLai   = max(0, $tongPhep - $daNghi);

        return [
            'id'               => $quota['id'] ?? null,
            'nhan_vien_id'     => $nhanVienId,
            'nam'              => $year,
            'phep_nam'         => (float)$quota['phep_nam'],
            'phep_tham_nien'   => (float)$quota['phep_tham_nien'],
            'phep_chuyen_sang' => (float)$quota['phep_chuyen_sang'],
            'tong_phep'        => $tongPhep,
            'so_ngay_da_nghi'  => $daNghi,
            'phep_con_lai'     => $conLai,
        ];
    }
}

/* ============================================================
   ⭐ 8.1 HELPER MỚI — CHUẨN HOÁ CA NGHỈ
   ============================================================ */

/**
 * Chuẩn hoá ca nghỉ về 1 trong 3 giá trị hợp lệ của DB
 */
if (!function_exists('normalizeCaNghiDb')) {
    function normalizeCaNghiDb(?string $ca): string {
        $valid = ['ca_ngay', 'sang', 'chieu'];
        if ($ca === null || $ca === '') return 'ca_ngay';
        return in_array($ca, $valid, true) ? $ca : 'ca_ngay';
    }
}

/**
 * Nhãn tiếng Việt cho ca nghỉ
 */
if (!function_exists('caNghiLabel')) {
    function caNghiLabel(?string $ca): string {
        $map = [
            'ca_ngay' => 'Cả ngày',
            'sang'    => 'Buổi sáng',
            'chieu'   => 'Buổi chiều',
        ];
        return $map[normalizeCaNghiDb($ca)] ?? 'Cả ngày';
    }
}

/**
 * Kiểm tra 2 ca nghỉ có giống nhau không (dùng cho 1 ngày)
 */
if (!function_exists('isCaNghiSingle')) {
    function isCaNghiSingle(?string $start, ?string $end): bool {
        return normalizeCaNghiDb($start) === normalizeCaNghiDb($end);
    }
}

/**
 * Tính số ngày lịch từ khoảng ngày (inclusive)
 */
if (!function_exists('diffDaysInclusive')) {
    function diffDaysInclusive(?string $tuNgay, ?string $denNgay): int {
        if (empty($tuNgay) || empty($denNgay)) return 1;
        $t1 = strtotime($tuNgay);
        $t2 = strtotime($denNgay);
        if ($t1 === false || $t2 === false) return 1;
        return (int)floor(($t2 - $t1) / 86400) + 1;
    }
}

/* ============================================================
   9. CSRF HELPERS
   ============================================================ */
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validateCsrfToken')) {
    function validateCsrfToken(?string $token): bool {
        if (empty($_SESSION['csrf_token']) || empty($token)) return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('verifyCsrfRequestApi')) {
    function verifyCsrfRequestApi(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            if (!validateCsrfToken($token)) {
                jsonError('Phiên làm việc hết hạn hoặc CSRF token không hợp lệ. Vui lòng tải lại trang.', 419, 'csrf_invalid');
            }
        }
    }
}

/* ============================================================
   10. ĐỌC INPUT
   ============================================================ */
$id = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
} else {
    $id = (int)($_GET['id'] ?? 0);
}

$db = Database::getConnection();

/* ============================================================
   11. XỬ LÝ POST — DUYỆT / TỪ CHỐI / TIẾP NHẬN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0) {
    verifyCsrfRequestApi();
    $actionType = $_POST['action_type'] ?? '';

    /* ---------- 11.1 LÃNH ĐẠO / ADMIN PHÊ DUYỆT HOẶC TỪ CHỐI ---------- */
    if ($actionType === 'lanh_dao_decision' && hasRole([ROLE_LANH_DAO, ROLE_ADMIN])) {
        $decision = $_POST['decision'] ?? '';
        $yKien    = trim($_POST['phe_duyet_note'] ?? '');

        $stmtCheck = $db->prepare('
            SELECT np.*, nv.ho_ten, nv.ma_nv, nv.id AS nv_id
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            WHERE np.id = ? AND np.trang_thai IN (?, ?)
        ');
        $stmtCheck->execute([$id, STATUS_CHO_PHE_DUYET, STATUS_CHO_TRUONG_KHOA]);
        $don = $stmtCheck->fetch();

        if (!$don) {
            jsonError('Không tìm thấy đơn hoặc đơn không ở trạng thái chờ phê duyệt.', 404);
        }

        /* ⭐ Mô tả ca nghỉ để ghi log */
        $caStart = normalizeCaNghiDb($don['buoi_nghi'] ?? 'ca_ngay');
        $caEnd   = normalizeCaNghiDb($don['buoi_nghi_ket_thuc'] ?? $caStart);
        $moTaCa  = isCaNghiSingle($caStart, $caEnd)
            ? caNghiLabel($caStart)
            : caNghiLabel($caStart) . ' (đầu) → ' . caNghiLabel($caEnd) . ' (cuối)';

        $year       = (int)date('Y', strtotime($don['tu_ngay']));
        $nhanVienId = (int)$don['nv_id'];
        $soNgayNghi = (float)$don['so_ngay'];

        if ($decision === 'approve') {
            $signatureMethod  = $_POST['signature_method_ld'] ?? 'draw';
            $chuKyBase64      = $_POST['chu_ky_lanh_dao_base64'] ?? '';
            $chuKyLanhDaoPath = $don['chu_ky_lanh_dao'] ?? null;

            $currentEmp = getCurrentEmployee();
            $dungChuKyMacDinh = !empty($_POST['use_default_signature']) && !empty($currentEmp['chu_ky_mac_dinh']);

            if ($dungChuKyMacDinh) {
                $chuKyLanhDaoPath = $currentEmp['chu_ky_mac_dinh'];
            } else {
                $uploadDir = __DIR__ . '/../../frontend/uploads/signatures/';
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }

                if ($signatureMethod === 'upload') {
                    if (isset($_FILES['file_chu_ky_lanh_dao']) && $_FILES['file_chu_ky_lanh_dao']['error'] === UPLOAD_ERR_OK) {
                        $fileTmp = $_FILES['file_chu_ky_lanh_dao']['tmp_name'];

                        if (!empty($chuKyBase64) && str_starts_with($chuKyBase64, 'data:image')) {
                            $imageData = explode(',', $chuKyBase64);
                            if (isset($imageData[1])) {
                                $decoded = base64_decode($imageData[1]);
                                $pngFileName = 'lanh_dao_sign_' . $currentUserId . '_' . time() . '.png';
                                if (file_put_contents($uploadDir . $pngFileName, $decoded)) {
                                    $chuKyLanhDaoPath = 'frontend/uploads/signatures/' . $pngFileName;
                                }
                            }
                        }
                        if (!$chuKyLanhDaoPath) {
                            $fileName = 'lanh_dao_sign_' . $currentUserId . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['file_chu_ky_lanh_dao']['name']);
                            if (move_uploaded_file($fileTmp, $uploadDir . $fileName)) {
                                $chuKyLanhDaoPath = 'frontend/uploads/signatures/' . $fileName;
                            }
                        }
                    }
                } else {
                    if (!empty($chuKyBase64) && str_starts_with($chuKyBase64, 'data:image')) {
                        $imageData = explode(',', $chuKyBase64);
                        if (isset($imageData[1])) {
                            $decoded = base64_decode($imageData[1]);
                            $pngFileName = 'lanh_dao_canvas_' . $currentUserId . '_' . time() . '.png';
                            if (file_put_contents($uploadDir . $pngFileName, $decoded)) {
                                $chuKyLanhDaoPath = 'frontend/uploads/signatures/' . $pngFileName;
                            }
                        }
                    }
                }
            }

            if (!empty($_POST['save_as_default_sign']) && $currentEmpId > 0 && !empty($chuKyLanhDaoPath)) {
                $stmtSaveDefault = $db->prepare('UPDATE nhan_vien SET chu_ky_mac_dinh = ? WHERE id = ?');
                $stmtSaveDefault->execute([$chuKyLanhDaoPath, $currentEmpId]);
            }

            $db->beginTransaction();
            try {
                $newStatus = STATUS_DA_DUYET;
                $stmtUp = $db->prepare('
                    UPDATE nghi_phep
                    SET phe_duyet = ?, chu_ky_lanh_dao = ?,
                        nguoi_phe_duyet = ?, ngay_phe_duyet = NOW(),
                        trang_thai = ?
                    WHERE id = ?
                ');
                $stmtUp->execute([$yKien ?: 'Đồng ý phê duyệt', $chuKyLanhDaoPath, $currentUserId, $newStatus, $id]);

                recordLeaveHistory(
                    $id, $currentUserId,
                    'Lãnh đạo phê duyệt nghỉ phép' . ($chuKyLanhDaoPath ? ' (Kèm chữ ký số)' : ''),
                    $don['trang_thai'], $newStatus,
                    ($yKien ?: 'Phê duyệt cho nghỉ ' . $soNgayNghi . ' ngày') . ' — Ca nghỉ: ' . $moTaCa
                );

                $db->commit();

                $stmtUserNV = $db->prepare('SELECT id FROM users WHERE nhan_vien_id = ?');
                $stmtUserNV->execute([$nhanVienId]);
                $nvUserId = $stmtUserNV->fetchColumn();
                if ($nvUserId) {
                    createNotification(
                        (int)$nvUserId,
                        'Giấy nghỉ phép đã được Ban Giám Đốc phê duyệt',
                        "Đơn nghỉ phép {$don['ma_don']} của bạn đã được Lãnh đạo phê duyệt thành công ({$moTaCa}).",
                        '/nghi-phep/xem.php?id=' . $id
                    );
                }

                $stmtNS = $db->query('SELECT id FROM users WHERE vai_tro = "nhan_su"');
                while ($ns = $stmtNS->fetch()) {
                    createNotification(
                        $ns['id'],
                        'Có giấy nghỉ phép mới cần tiếp nhận',
                        "Đơn {$don['ma_don']} của {$don['ho_ten']} đã được Ban Giám Đốc duyệt ({$moTaCa}).",
                        '/nhan-su/tiep-nhan.php'
                    );
                }

                jsonSuccess([
                    'id'      => $id,
                    'status'  => $newStatus,
                    'message' => "Đã phê duyệt giấy nghỉ phép {$don['ma_don']} thành công!"
                ], "Đã phê duyệt giấy nghỉ phép {$don['ma_don']} thành công!");
            } catch (Exception $e) {
                $db->rollBack();
                jsonError('Lỗi trong quá trình phê duyệt: ' . $e->getMessage(), 500);
            }
        } elseif ($decision === 'reject') {
            $newStatus = STATUS_TU_CHOI;
            $stmtUp = $db->prepare('
                UPDATE nghi_phep
                SET phe_duyet = ?, nguoi_phe_duyet = ?, ngay_phe_duyet = NOW(),
                    trang_thai = ?, ly_do_tu_choi = ?
                WHERE id = ?
            ');
            $stmtUp->execute([$yKien ?: 'Không đồng ý phê duyệt', $currentUserId, $newStatus, $yKien, $id]);

            recordLeaveHistory(
                $id, $currentUserId, 'Lãnh đạo từ chối đơn',
                $don['trang_thai'], $newStatus,
                ($yKien ?: 'Lãnh đạo không chấp thuận nghỉ phép') . ' — Ca nghỉ: ' . $moTaCa
            );

            $stmtUserNV = $db->prepare('SELECT id FROM users WHERE nhan_vien_id = ?');
            $stmtUserNV->execute([$nhanVienId]);
            $nvUserId = $stmtUserNV->fetchColumn();
            if ($nvUserId) {
                createNotification(
                    (int)$nvUserId,
                    'Giấy nghỉ phép của bạn bị từ chối',
                    "Ban Giám Đốc không chấp thuận đơn {$don['ma_don']}. Lý do: {$yKien}",
                    '/nghi-phep/xem.php?id=' . $id
                );
            }

            jsonSuccess([
                'id'      => $id,
                'status'  => $newStatus,
                'message' => "Đã từ chối giấy nghỉ phép {$don['ma_don']}!"
            ], "Đã từ chối giấy nghỉ phép {$don['ma_don']}!");
        } else {
            jsonError('Hành động không hợp lệ.', 400);
        }
    }

    /* ---------- 11.2 TRƯỞNG KHOA / ADMIN CHO Ý KIẾN ---------- */
    if ($actionType === 'truong_khoa_decision' && hasRole([ROLE_TRUONG_KHOA, ROLE_ADMIN])) {
        $decision = $_POST['decision'] ?? '';
        $yKien    = trim($_POST['y_kien_note'] ?? '');

        $stmtCheck = $db->prepare('
            SELECT np.*, nv.ho_ten, nv.khoa_phong_id, nv.id AS nv_id
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            WHERE np.id = ? AND np.trang_thai = ?
        ');
        $stmtCheck->execute([$id, STATUS_CHO_TRUONG_KHOA]);
        $don = $stmtCheck->fetch();

        if (!$don) {
            jsonError('Không tìm thấy đơn hoặc đơn không ở trạng thái chờ trưởng khoa.', 404);
        }

        /* ⭐ Mô tả ca nghỉ để ghi log */
        $caStart = normalizeCaNghiDb($don['buoi_nghi'] ?? 'ca_ngay');
        $caEnd   = normalizeCaNghiDb($don['buoi_nghi_ket_thuc'] ?? $caStart);
        $moTaCa  = isCaNghiSingle($caStart, $caEnd)
            ? caNghiLabel($caStart)
            : caNghiLabel($caStart) . ' (đầu) → ' . caNghiLabel($caEnd) . ' (cuối)';

        if ($decision === 'approve') {
            $signatureMethodTk = $_POST['signature_method_tk'] ?? 'draw';
            $chuKyBase64Tk     = $_POST['chu_ky_truong_khoa_base64'] ?? '';
            $chuKyTkPath       = $don['chu_ky_truong_khoa'] ?? null;

            $currentEmp = getCurrentEmployee();
            $dungChuKyMacDinhTk = !empty($_POST['use_default_signature_tk']) && !empty($currentEmp['chu_ky_mac_dinh']);

            if ($dungChuKyMacDinhTk) {
                $chuKyTkPath = $currentEmp['chu_ky_mac_dinh'];
            } else {
                $uploadDir = __DIR__ . '/../../frontend/uploads/signatures/';
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }

                if ($signatureMethodTk === 'upload') {
                    if (isset($_FILES['file_chu_ky_truong_khoa']) && $_FILES['file_chu_ky_truong_khoa']['error'] === UPLOAD_ERR_OK) {
                        $fileTmp = $_FILES['file_chu_ky_truong_khoa']['tmp_name'];

                        if (!empty($chuKyBase64Tk) && str_starts_with($chuKyBase64Tk, 'data:image')) {
                            $imageData = explode(',', $chuKyBase64Tk);
                            if (isset($imageData[1])) {
                                $decoded = base64_decode($imageData[1]);
                                $pngFileName = 'truong_khoa_sign_' . $currentUserId . '_' . time() . '.png';
                                if (file_put_contents($uploadDir . $pngFileName, $decoded)) {
                                    $chuKyTkPath = 'frontend/uploads/signatures/' . $pngFileName;
                                }
                            }
                        }
                        if (!$chuKyTkPath) {
                            $fileName = 'truong_khoa_sign_' . $currentUserId . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['file_chu_ky_truong_khoa']['name']);
                            if (move_uploaded_file($fileTmp, $uploadDir . $fileName)) {
                                $chuKyTkPath = 'frontend/uploads/signatures/' . $fileName;
                            }
                        }
                    }
                } else {
                    if (!empty($chuKyBase64Tk) && str_starts_with($chuKyBase64Tk, 'data:image')) {
                        $imageData = explode(',', $chuKyBase64Tk);
                        if (isset($imageData[1])) {
                            $decoded = base64_decode($imageData[1]);
                            $pngFileName = 'truong_khoa_canvas_' . $currentUserId . '_' . time() . '.png';
                            if (file_put_contents($uploadDir . $pngFileName, $decoded)) {
                                $chuKyTkPath = 'frontend/uploads/signatures/' . $pngFileName;
                            }
                        }
                    }
                }
            }

            if (!empty($_POST['save_as_default_sign_tk']) && $currentEmpId > 0 && !empty($chuKyTkPath)) {
                $stmtSaveDefault = $db->prepare('UPDATE nhan_vien SET chu_ky_mac_dinh = ? WHERE id = ?');
                $stmtSaveDefault->execute([$chuKyTkPath, $currentEmpId]);
            }

            $newStatus = STATUS_CHO_PHE_DUYET;
            $stmtUp = $db->prepare('
                UPDATE nghi_phep
                SET y_kien_truong_khoa = ?, chu_ky_truong_khoa = ?,
                    nguoi_cho_y_kien = ?, ngay_cho_y_kien = NOW(),
                    trang_thai = ?
                WHERE id = ?
            ');
            $stmtUp->execute([$yKien ?: 'Đồng ý cho nghỉ theo nguyện vọng', $chuKyTkPath, $currentUserId, $newStatus, $id]);

            recordLeaveHistory(
                $id, $currentUserId,
                'Trưởng khoa đồng ý & chuyển Lãnh đạo' . ($chuKyTkPath ? ' (Kèm chữ ký số)' : ''),
                $don['trang_thai'], $newStatus,
                ($yKien ?: 'Đồng ý cho nghỉ theo nguyện vọng') . ' — Ca nghỉ: ' . $moTaCa
            );

            $stmtLD = $db->query('SELECT id FROM users WHERE vai_tro = "lanh_dao"');
            while ($ld = $stmtLD->fetch()) {
                createNotification(
                    $ld['id'],
                    'Có giấy nghỉ phép mới cần phê duyệt',
                    "Trưởng khoa đã cho ý kiến đơn của {$don['ho_ten']} ({$don['ma_don']}) — Ca nghỉ: {$moTaCa}",
                    '/phe-duyet/lanh-dao.php'
                );
            }

            jsonSuccess([
                'id'      => $id,
                'status'  => $newStatus,
                'message' => 'Đã ghi nhận ý kiến và chuyển giấy nghỉ phép lên Lãnh đạo phê duyệt!'
            ], 'Đã ghi nhận ý kiến và chuyển giấy nghỉ phép lên Lãnh đạo phê duyệt!');
        } elseif ($decision === 'reject') {
            $newStatus = STATUS_TU_CHOI;
            $stmtUp = $db->prepare('
                UPDATE nghi_phep
                SET y_kien_truong_khoa = ?, nguoi_cho_y_kien = ?, ngay_cho_y_kien = NOW(),
                    trang_thai = ?, ly_do_tu_choi = ?
                WHERE id = ?
            ');
            $stmtUp->execute([$yKien ?: 'Trưởng khoa không đồng ý', $currentUserId, $newStatus, $yKien, $id]);

            recordLeaveHistory(
                $id, $currentUserId, 'Trưởng khoa từ chối đơn',
                $don['trang_thai'], $newStatus,
                ($yKien ?: 'Không chấp thuận nghỉ phép') . ' — Ca nghỉ: ' . $moTaCa
            );

            $stmtUserNV = $db->prepare('SELECT id FROM users WHERE nhan_vien_id = ?');
            $stmtUserNV->execute([$don['nv_id']]);
            $nvUserId = $stmtUserNV->fetchColumn();
            if ($nvUserId) {
                createNotification(
                    (int)$nvUserId,
                    'Giấy nghỉ phép của bạn bị từ chối',
                    "Trưởng khoa không chấp thuận đơn {$don['ma_don']}. Lý do: {$yKien}",
                    '/nghi-phep/xem.php?id=' . $id
                );
            }

            jsonSuccess([
                'id'      => $id,
                'status'  => $newStatus,
                'message' => 'Đã từ chối giấy nghỉ phép thành công!'
            ], 'Đã từ chối giấy nghỉ phép thành công!');
        } else {
            jsonError('Hành động không hợp lệ.', 400);
        }
    }

    /* ---------- 11.3 NHÂN SỰ TIẾP NHẬN GIẤY ---------- */
    if ($actionType === 'nhan_su_receive' && hasRole([ROLE_NHAN_SU, ROLE_ADMIN])) {
        $ngayNopInput = trim($_POST['ngay_nop_nhan_su'] ?? '');

        $stmtCheck = $db->prepare('
            SELECT np.*, nv.ho_ten, nv.id AS nv_id
            FROM nghi_phep np
            JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
            WHERE np.id = ? AND np.trang_thai = ?
        ');
        $stmtCheck->execute([$id, STATUS_DA_DUYET]);
        $don = $stmtCheck->fetch();

        if (!$don) {
            jsonError('Không tìm thấy đơn hoặc đơn chưa được duyệt.', 404);
        }

        /* ⭐ Mô tả ca nghỉ để ghi log */
        $caStart = normalizeCaNghiDb($don['buoi_nghi'] ?? 'ca_ngay');
        $caEnd   = normalizeCaNghiDb($don['buoi_nghi_ket_thuc'] ?? $caStart);
        $moTaCa  = isCaNghiSingle($caStart, $caEnd)
            ? caNghiLabel($caStart)
            : caNghiLabel($caStart) . ' (đầu) → ' . caNghiLabel($caEnd) . ' (cuối)';

        $ngayNop = !empty($ngayNopInput) ? $ngayNopInput : date('Y-m-d', strtotime($don['created_at']));
        $db->beginTransaction();
        try {
            $nhanVienId = (int)$don['nv_id'];
            $soNgay     = (float)$don['so_ngay'];
            $year       = (int)date('Y', strtotime($don['tu_ngay']));

            if (empty($don['da_tru_phep'])) {
                deductLeaveDaysSecure($id, $nhanVienId, $soNgay, $year);
            }

            $stmtUpdate = $db->prepare('
                UPDATE nghi_phep
                SET ngay_nop_nhan_su = ?, nguoi_tiep_nhan = ?, ngay_tiep_nhan = NOW(),
                    trang_thai = ?
                WHERE id = ?
            ');
            $stmtUpdate->execute([$ngayNop, $currentUserId, STATUS_DA_TIEP_NHAN, $id]);

            recordLeaveHistory(
                $id, $currentUserId,
                'Phòng Nhân sự đã tiếp nhận giấy',
                STATUS_DA_DUYET, STATUS_DA_TIEP_NHAN,
                "Ghi nhận Ngày nộp giấy: " . formatDate($ngayNop)
                    . " (Thời gian tiếp nhận hệ thống: " . date('d/m/Y H:i') . ")"
                    . " — Ca nghỉ: " . $moTaCa
                    . " — Số ngày trừ: " . $soNgay
            );

            $db->commit();

            $stmtUserNV = $db->prepare('SELECT id FROM users WHERE nhan_vien_id = ?');
            $stmtUserNV->execute([$nhanVienId]);
            $nvUserId = $stmtUserNV->fetchColumn();
            if ($nvUserId) {
                createNotification(
                    (int)$nvUserId,
                    'Phòng Nhân sự đã tiếp nhận giấy nghỉ phép',
                    "Đơn {$don['ma_don']} của bạn đã được Phòng Nhân sự tiếp nhận (Ngày nộp: " . formatDate($ngayNop) . ") — Ca nghỉ: {$moTaCa}.",
                    '/nghi-phep/xem.php?id=' . $id
                );
            }

            jsonSuccess([
                'id'       => $id,
                'status'   => STATUS_DA_TIEP_NHAN,
                'ngay_nop' => $ngayNop,
                'message'  => "Đã xác nhận tiếp nhận giấy nghỉ phép {$don['ma_don']} (Ngày nộp: " . formatDate($ngayNop) . ") thành công!"
            ], "Đã xác nhận tiếp nhận giấy nghỉ phép {$don['ma_don']} thành công!");
        } catch (Exception $e) {
            $db->rollBack();
            jsonError('Lỗi khi tiếp nhận: ' . $e->getMessage(), 500);
        }
    }

    jsonError('Hành động không được hỗ trợ.', 400);
}

/* ============================================================
   12. XỬ LÝ GET — LẤY CHI TIẾT ĐƠN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method không được hỗ trợ.', 405, 'method_not_allowed');
}

if ($id <= 0) {
    jsonError('Mã giấy nghỉ phép không hợp lệ!', 400, 'invalid_id');
}

$stmt = $db->prepare('
    SELECT np.*,
           nv.ho_ten, nv.ma_nv, nv.chuc_vu, nv.khoa_phong_id,
           kp.ten_khoa,
           u_tk.username AS truong_khoa_user,
           u_ld.username AS lanh_dao_user,
           u_ns.username AS nhan_su_user,
           nv_ns.ho_ten AS nhan_su_ten
    FROM nghi_phep np
    JOIN nhan_vien nv ON np.nhan_vien_id = nv.id
    JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
    LEFT JOIN users u_tk ON np.nguoi_cho_y_kien = u_tk.id
    LEFT JOIN users u_ld ON np.nguoi_phe_duyet = u_ld.id
    LEFT JOIN users u_ns ON np.nguoi_tiep_nhan = u_ns.id
    LEFT JOIN nhan_vien nv_ns ON u_ns.nhan_vien_id = nv_ns.id
    WHERE np.id = ?
');
$stmt->execute([$id]);
$leave = $stmt->fetch();

if (!$leave) {
    jsonError('Không tìm thấy thông tin giấy nghỉ phép này!', 404, 'not_found');
}

/* ---------- Kiểm tra quyền xem ---------- */
if ($userRole === ROLE_NHAN_VIEN && (int)$leave['nhan_vien_id'] !== (int)$currentEmpId) {
    jsonError('Bạn không có quyền xem giấy nghỉ phép của nhân viên khác!', 403, 'forbidden');
}

/* ---------- Lịch sử ---------- */
$stmtHistory = $db->prepare('
    SELECT ls.*, u.username, nv.ho_ten AS nguoi_thuc_hien_ten, u.vai_tro
    FROM nghi_phep_lich_su ls
    JOIN users u ON ls.user_id = u.id
    LEFT JOIN nhan_vien nv ON u.nhan_vien_id = nv.id
    WHERE ls.nghi_phep_id = ?
    ORDER BY ls.created_at ASC
');
$stmtHistory->execute([$id]);
$historyList = $stmtHistory->fetchAll();

/* ---------- Phép năm ---------- */
$leaveYear = (int)date('Y', strtotime($leave['tu_ngay']));
$quota     = getEmployeeLeaveQuota((int)$leave['nhan_vien_id'], $leaveYear);

/* ============================================================
   ⭐ 12.1 CHUẨN HOÁ CA NGHỈ
   ============================================================ */
$caNghiStart    = normalizeCaNghiDb($leave['buoi_nghi']            ?? 'ca_ngay');
$caNghiEnd      = normalizeCaNghiDb($leave['buoi_nghi_ket_thuc']   ?? $caNghiStart);
$totalDaysRange = diffDaysInclusive($leave['tu_ngay'] ?? null, $leave['den_ngay'] ?? null);
$caNghiLabelStr = isCaNghiSingle($caNghiStart, $caNghiEnd)
    ? caNghiLabel($caNghiStart)
    : caNghiLabel($caNghiStart) . ' → ' . caNghiLabel($caNghiEnd);

/* ============================================================
   ⭐ 12.2 CHUẨN HOÁ DỮ LIỆU TRẢ VỀ
   ============================================================ */
$leaveData = [
    'id'                        => (int)$leave['id'],
    'ma_don'                    => $leave['ma_don'] ?? '',
    'nhan_vien_id'              => (int)$leave['nhan_vien_id'],
    'ho_ten'                    => $leave['ho_ten'] ?? '',
    'ma_nv'                     => $leave['ma_nv'] ?? '',
    'chuc_vu'                   => $leave['chuc_vu'] ?? '',
    'ten_khoa'                  => $leave['ten_khoa'] ?? '',
    'tu_ngay'                   => $leave['tu_ngay'] ?? null,
    'den_ngay'                  => $leave['den_ngay'] ?? null,
    'so_ngay'                   => (float)($leave['so_ngay'] ?? 0),

    /* ⭐ 2 cột ca nghỉ */
    'buoi_nghi'                 => $caNghiStart,           // 'ca_ngay' | 'sang' | 'chieu'
    'buoi_nghi_ket_thuc'        => $caNghiEnd,             // 'ca_ngay' | 'sang' | 'chieu'
    'ca_nghi_label'             => $caNghiLabelStr,        // chuỗi gợi ý hiển thị
    'is_nghi_nua_buoi'          => isCaNghiSingle($caNghiStart, $caNghiEnd)
                                    && $caNghiStart !== 'ca_ngay',
    'total_days_range'          => $totalDaysRange,        // số ngày lịch

    'ly_do'                     => $leave['ly_do'] ?? '',
    'trang_thai'                => $leave['trang_thai'] ?? '',
    'created_at'                => $leave['created_at'] ?? null,
    'chu_ky_nguoi_nghi'         => $leave['chu_ky_nguoi_nghi'] ?? null,
    'y_kien_truong_khoa'        => $leave['y_kien_truong_khoa'] ?? null,
    'chu_ky_truong_khoa'        => $leave['chu_ky_truong_khoa'] ?? null,
    'ngay_cho_y_kien'           => $leave['ngay_cho_y_kien'] ?? null,
    'phe_duyet'                 => $leave['phe_duyet'] ?? null,
    'chu_ky_lanh_dao'           => $leave['chu_ky_lanh_dao'] ?? null,
    'ngay_phe_duyet'            => $leave['ngay_phe_duyet'] ?? null,
    'ngay_nop_nhan_su'          => $leave['ngay_nop_nhan_su'] ?? null,
    'ngay_tiep_nhan'            => $leave['ngay_tiep_nhan'] ?? null,
    'nhan_su_ten'               => $leave['nhan_su_ten'] ?? null,
    'nhan_su_user'              => $leave['nhan_su_user'] ?? null,
    'so_phep_con_lai_luc_nghi'  => $leave['so_phep_con_lai_luc_nghi'] !== null
                                    ? (float)$leave['so_phep_con_lai_luc_nghi']
                                    : null,
    'ly_do_tu_choi'             => $leave['ly_do_tu_choi'] ?? null,
];

$historyData = array_map(function ($h) {
    return [
        'id'                  => (int)$h['id'],
        'thao_tac'            => $h['thao_tac'] ?? '',
        'nguoi_thuc_hien_ten' => $h['nguoi_thuc_hien_ten'] ?? '',
        'username'            => $h['username'] ?? '',
        'vai_tro'             => $h['vai_tro'] ?? '',
        'trang_thai_cu'       => $h['trang_thai_cu'] ?? null,
        'trang_thai_moi'      => $h['trang_thai_moi'] ?? null,
        'ghi_chu'             => $h['ghi_chu'] ?? null,
        'created_at'          => $h['created_at'] ?? null,
    ];
}, $historyList);

/* ============================================================
   13. LẤY THÔNG TIN USER ĐỂ FE RENDER NAVBAR
   ============================================================ */
$currentEmpForFe = getCurrentEmployee();
$userForFe = [
    'id'            => (int)$currentUserId,
    'username'      => $currentUser['username'] ?? '',
    'vai_tro'       => $userRole,
    'nhan_vien_id'  => $currentEmpId ?: null,
    'ho_ten'        => $currentEmpForFe['ho_ten']        ?? $currentUser['username'] ?? 'Người dùng',
    'ma_nv'         => $currentEmpForFe['ma_nv']         ?? null,
    'email'         => $currentEmpForFe['email']         ?? null,
    'so_dien_thoai' => $currentEmpForFe['so_dien_thoai'] ?? null,
    'ngay_sinh'     => $currentEmpForFe['ngay_sinh']     ?? null,
    'chuc_vu'       => $currentEmpForFe['chuc_vu']       ?? null,
    'ten_khoa'      => $currentEmpForFe['ten_khoa']      ?? null,
    'ma_khoa'       => $currentEmpForFe['ma_khoa']       ?? null,
    'anh_dai_dien'  => $currentEmpForFe['anh_dai_dien']  ?? null,
];

/* ============================================================
   14. RESPONSE JSON
   ============================================================ */
jsonSuccess([
    'user'       => $userForFe,
    'leave'      => $leaveData,
    'history'    => $historyData,
    'quota'      => [
        'tong_phep'       => (float)$quota['tong_phep'],
        'so_ngay_da_nghi' => (float)$quota['so_ngay_da_nghi'],
        'phep_con_lai'    => (float)$quota['phep_con_lai'],
    ],
    'leave_year' => $leaveYear,
]);