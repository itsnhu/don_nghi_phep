<?php
/**
 * ============================================================
 *  HÀM TIỆN ÍCH + XÁC THỰC (AUTH) + XỬ LÝ NGHIỆP VỤ
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 * ------------------------------------------------------------
 *  GỘP 2 FILE: functions.php (cũ) + auth.php (cũ)
 *  - Session đã được start trong app.php → không start lại
 *  - Mọi hàm đều có function_exists() guard
 *  - jsonResponse/jsonSuccess/jsonError dùng return type "never"
 * ============================================================
 */

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/ketnoisql.php';

/* ============================================================
 * 1. HẰNG SỐ TRẠNG THÁI ĐƠN NGHỈ PHÉP + VAI TRÒ
 * ============================================================ */
if (!defined('STATUS_DRAFT'))            define('STATUS_DRAFT',            'draft');
if (!defined('STATUS_CHO_TRUONG_KHOA'))  define('STATUS_CHO_TRUONG_KHOA',  'cho_truong_khoa');
if (!defined('STATUS_CHO_PHE_DUYET'))    define('STATUS_CHO_PHE_DUYET',    'cho_phe_duyet');
if (!defined('STATUS_DA_DUYET'))         define('STATUS_DA_DUYET',         'da_duyet');
if (!defined('STATUS_TU_CHOI'))          define('STATUS_TU_CHOI',          'tu_choi');
if (!defined('STATUS_DA_TIEP_NHAN'))     define('STATUS_DA_TIEP_NHAN',     'da_tiep_nhan');

if (!defined('ROLE_GIAM_DOC'))           define('ROLE_GIAM_DOC',           'giam_doc');
if (!defined('ROLE_TRUONG_KHOA'))        define('ROLE_TRUONG_KHOA',        'truong_khoa');
if (!defined('ROLE_NHAN_SU'))            define('ROLE_NHAN_SU',            'nhan_su');
if (!defined('ROLE_NHAN_VIEN'))          define('ROLE_NHAN_VIEN',          'nhan_vien');
if (!defined('ROLE_ADMIN'))              define('ROLE_ADMIN',              'admin');


/* ============================================================
 * ███████╗██╗   ██╗███╗   ██╗ ██████╗████████╗██╗ ██████╗ ███╗   ██╗███████╗
 * ██╔════╝██║   ██║████╗  ██║██╔════╝╚══██╔══╝██║██╔═══██╗████╗  ██║██╔════╝
 * █████╗  ██║   ██║██╔██╗ ██║██║        ██║   ██║██║   ██║██╔██╗ ██║███████╗
 * ██╔══╝  ██║   ██║██║╚██╗██║██║        ██║   ██║██║   ██║██║╚██╗██║╚════██║
 * ██║     ╚██████╔╝██║ ╚████║╚██████╗   ██║   ██║╚██████╔╝██║ ╚████║███████║
 * ╚═╝      ╚═════╝ ╚═╝  ╚═══╝ ╚═════╝   ╚═╝   ╚═╝ ╚═════╝ ╚═╝  ╚═══╝╚══════╝
 * ============================================================ */

/* ------------------------------------------------------------
 * 2.1. ESCAPE / FORMAT
 * ------------------------------------------------------------ */

if (!function_exists('e')) {
    function e(?string $string): string {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('formatDate')) {
    function formatDate(?string $dateStr): string {
        if (empty($dateStr) || $dateStr === '0000-00-00') {
            return '-';
        }
        $time = strtotime($dateStr);
        return $time ? date('d/m/Y', $time) : '-';
    }
}

if (!function_exists('formatDateTime')) {
    function formatDateTime(?string $dateTimeStr): string {
        if (empty($dateTimeStr) || $dateTimeStr === '0000-00-00 00:00:00') {
            return '-';
        }
        $time = strtotime($dateTimeStr);
        return $time ? date('d/m/Y H:i', $time) : '-';
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

/* ------------------------------------------------------------
 * 2.2. TRẠNG THÁI ĐƠN — TÊN + BADGE
 * ------------------------------------------------------------ */

if (!function_exists('getStatusName')) {
    function getStatusName(string $status): string {
        return match ($status) {
            STATUS_DRAFT           => 'Bản nháp',
            STATUS_CHO_TRUONG_KHOA => 'Chờ Trưởng khoa/phòng',
            STATUS_CHO_PHE_DUYET   => 'Chờ Lãnh đạo phê duyệt',
            STATUS_DA_DUYET        => 'Đã duyệt',
            STATUS_TU_CHOI         => 'Từ chối',
            STATUS_DA_TIEP_NHAN    => 'Phòng Nhân sự đã tiếp nhận',
            default                => 'Không xác định'
        };
    }
}

if (!function_exists('getStatusBadge')) {
    function getStatusBadge(string $status): string {
        return match ($status) {
            STATUS_DRAFT           => '<span class="badge bg-secondary"><i class="fas fa-pencil-alt me-1"></i>Bản nháp</span>',
            STATUS_CHO_TRUONG_KHOA => '<span class="badge bg-warning text-dark"><i class="fas fa-user-clock me-1"></i>Chờ trưởng khoa</span>',
            STATUS_CHO_PHE_DUYET   => '<span class="badge bg-info text-dark"><i class="fas fa-hourglass-half me-1"></i>Chờ phê duyệt</span>',
            STATUS_DA_DUYET        => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Đã duyệt</span>',
            STATUS_TU_CHOI         => '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Từ chối</span>',
            STATUS_DA_TIEP_NHAN    => '<span class="badge bg-primary"><i class="fas fa-folder-check me-1"></i>Đã tiếp nhận</span>',
            default                => '<span class="badge bg-secondary">' . e($status) . '</span>'
        };
    }
}

/* ------------------------------------------------------------
 * 2.3. TÍNH TOÁN NGÀY NGHỈ PHÉP
 * ------------------------------------------------------------ */

if (!function_exists('calculateLeaveDays')) {
    function calculateLeaveDays(string $startDate, string $endDate, string $buoiNghi = 'ca_ngay'): float {
        if ($buoiNghi === 'sang' || $buoiNghi === 'chieu') {
            return 0.5;
        }
        try {
            $start = new DateTime($startDate);
            $end   = new DateTime($endDate);
        } catch (Exception $e) {
            return 0.0;
        }
        if ($end < $start) {
            return 0.0;
        }
        $diff = $start->diff($end);
        return (float)($diff->days + 1);
    }
}

if (!function_exists('getEmployeeLeaveQuota')) {
    function getEmployeeLeaveQuota(int $nhanVienId, ?int $year = null): array {
        $year = $year ?? (defined('CURRENT_YEAR') ? CURRENT_YEAR : (int)date('Y'));
        $db   = Database::getConnection();

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

if (!function_exists('generateLeaveCode')) {
    function generateLeaveCode(): string {
        $db    = Database::getConnection();
        $year  = date('Y');
        $stmt  = $db->prepare("SELECT COUNT(*) FROM nghi_phep WHERE ma_don LIKE ?");
        $stmt->execute(["NP-{$year}-%"]);
        $count = (int)$stmt->fetchColumn() + 1;
        return sprintf("NP-%s-%04d", $year, $count);
    }
}

/* ------------------------------------------------------------
 * 2.4. LỊCH SỬ + TRỪ / HOÀN PHÉP
 * ------------------------------------------------------------ */

if (!function_exists('recordLeaveHistory')) {
    function recordLeaveHistory(
        int $nghiPhepId,
        int $userId,
        string $thaoTac,
        ?string $oldStatus,
        string $newStatus,
        ?string $note = null
    ): bool {
        try {
            $db   = Database::getConnection();
            $stmt = $db->prepare('
                INSERT INTO nghi_phep_lich_su
                    (nghi_phep_id, user_id, thao_tac, trang_thai_cu, trang_thai_moi, ghi_chu)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            return $stmt->execute([$nghiPhepId, $userId, $thaoTac, $oldStatus, $newStatus, $note]);
        } catch (Exception $e) {
            error_log('Error recordLeaveHistory: ' . $e->getMessage());
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
            $quota = [
                'phep_nam'         => 12.0,
                'phep_tham_nien'   => 0.0,
                'phep_chuyen_sang' => 0.0,
                'so_ngay_da_nghi'  => 0.0,
            ];
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

if (!function_exists('deductLeaveDays')) {
    function deductLeaveDays(int $nghiPhepId, int $nhanVienId, float $soNgay, int $year): bool {
        return deductLeaveDaysSecure($nghiPhepId, $nhanVienId, $soNgay, $year);
    }
}

if (!function_exists('refundLeaveDays')) {
    function refundLeaveDays(int $nghiPhepId, int $nhanVienId, float $soNgay, int $year): bool {
        $db = Database::getConnection();
        try {
            $stmt = $db->prepare('
                UPDATE phep_nam
                SET so_ngay_da_nghi = GREATEST(0, so_ngay_da_nghi - ?)
                WHERE nhan_vien_id = ? AND nam = ?
            ');
            $stmt->execute([$soNgay, $nhanVienId, $year]);

            $stmtDon = $db->prepare('UPDATE nghi_phep SET da_tru_phep = 0 WHERE id = ?');
            $stmtDon->execute([$nghiPhepId]);
            return true;
        } catch (Exception $e) {
            error_log('Error refundLeaveDays: ' . $e->getMessage());
            return false;
        }
    }
}

/* ------------------------------------------------------------
 * 2.5. THÔNG BÁO (NOTIFICATIONS)
 * ------------------------------------------------------------ */

if (!function_exists('createNotification')) {
    function createNotification(int $userId, string $title, string $content, ?string $link = null): bool {
        try {
            $db   = Database::getConnection();
            $stmt = $db->prepare('
                INSERT INTO notifications (user_id, tieu_de, noi_dung, link)
                VALUES (?, ?, ?, ?)
            ');
            return $stmt->execute([$userId, $title, $content, $link]);
        } catch (Exception $e) {
            error_log('Error createNotification: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('getUnreadNotifications')) {
    function getUnreadNotifications(int $userId, int $limit = 20): array {
        try {
            $db   = Database::getConnection();
            $stmt = $db->prepare('
                SELECT * FROM notifications
                WHERE user_id = ? AND da_doc = 0
                ORDER BY created_at DESC
                LIMIT ?
            ');
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error getUnreadNotifications: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('countUnreadNotifications')) {
    function countUnreadNotifications(int $userId): int {
        try {
            $db   = Database::getConnection();
            $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND da_doc = 0');
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log('Error countUnreadNotifications: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('countAllNotifications')) {
    function countAllNotifications(int $userId): int {
        try {
            $db   = Database::getConnection();
            $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log('Error countAllNotifications: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('getAllNotifications')) {
    function getAllNotifications(int $userId, int $limit = 50, int $offset = 0): array {
        try {
            $db   = Database::getConnection();
            $stmt = $db->prepare('
                SELECT * FROM notifications
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ');
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error getAllNotifications: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('markNotificationAsRead')) {
    function markNotificationAsRead(int $notifId, int $userId): bool {
        try {
            $db   = Database::getConnection();
            $stmt = $db->prepare('UPDATE notifications SET da_doc = 1 WHERE id = ? AND user_id = ?');
            return $stmt->execute([$notifId, $userId]);
        } catch (Exception $e) {
            error_log('Error markNotificationAsRead: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('markAllNotificationsAsRead')) {
    function markAllNotificationsAsRead(int $userId): bool {
        try {
            $db   = Database::getConnection();
            $stmt = $db->prepare('UPDATE notifications SET da_doc = 1 WHERE user_id = ? AND da_doc = 0');
            return $stmt->execute([$userId]);
        } catch (Exception $e) {
            error_log('Error markAllNotificationsAsRead: ' . $e->getMessage());
            return false;
        }
    }
}

/* ------------------------------------------------------------
 * 2.6. FLASH MESSAGE
 * ------------------------------------------------------------ */

if (!function_exists('setFlashMessage')) {
    function setFlashMessage(string $type, string $message): void {
        if ($type === 'success') {
            $_SESSION['flash_success'] = $message;
        } else {
            $_SESSION['flash_error'] = $message;
        }
    }
}

if (!function_exists('getFlashMessage')) {
    function getFlashMessage(string $type = 'success'): ?string {
        $key = $type === 'success' ? 'flash_success' : 'flash_error';
        if (!empty($_SESSION[$key])) {
            $msg = $_SESSION[$key];
            unset($_SESSION[$key]);
            return $msg;
        }
        return null;
    }
}


/* ============================================================
 * █████╗ ██╗   ██╗████████╗██╗  ██╗
 * ██╔══██╗██║   ██║╚══██╔══╝██║  ██║
 * ███████║██║   ██║   ██║   ███████║
 * ██╔══██║██║   ██║   ██║   ██╔══██║
 * ██║  ██║╚██████╔╝   ██║   ██║  ██║
 * ╚═╝  ╚═╝ ╚═════╝    ╚═╝   ╚═╝  ╚═╝
 * ============================================================ */

/* ------------------------------------------------------------
 * 3.1. KIỂM TRA ĐĂNG NHẬP
 * ------------------------------------------------------------ */

if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool {
        return isset($_SESSION['user']) && !empty($_SESSION['user']['id']);
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin(): void {
        if (isLoggedIn()) {
            return;
        }

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

        if ($isAjax) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8', true, 401);
            }
            echo json_encode([
                'success'      => false,
                'message'      => 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại!',
                'redirect_url' => BASE_URL . '/frontend/dangnhap_quenmk/dangnhap.html',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $_SESSION['flash_error'] = 'Vui lòng đăng nhập để tiếp tục!';
        header('Location: ' . BASE_URL . '/frontend/dangnhap_quenmk/dangnhap.html');
        exit;
    }
}

if (!function_exists('getCurrentUser')) {
    function getCurrentUser(): ?array {
        return $_SESSION['user'] ?? null;
    }
}

if (!function_exists('getCurrentEmployee')) {
    function getCurrentEmployee(): ?array {
        $user = getCurrentUser();
        if (!$user || empty($user['nhan_vien_id'])) {
            return null;
        }

        try {
            $db   = Database::getConnection();
            $stmt = $db->prepare('
                SELECT nv.*, kp.ten_khoa, kp.ma_khoa
                FROM nhan_vien nv
                LEFT JOIN khoa_phong kp ON nv.khoa_phong_id = kp.id
                WHERE nv.id = ?
            ');
            $stmt->execute([$user['nhan_vien_id']]);
            return $stmt->fetch() ?: null;
        } catch (Exception $e) {
            error_log('Error getCurrentEmployee: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('hasRole')) {
    function hasRole(string $role): bool {
        $user = getCurrentUser();
        return $user && isset($user['vai_tro']) && $user['vai_tro'] === $role;
    }
}

if (!function_exists('hasAnyRole')) {
    function hasAnyRole(array $roles): bool {
        $user = getCurrentUser();
        return $user && isset($user['vai_tro']) && in_array($user['vai_tro'], $roles, true);
    }
}

/* ------------------------------------------------------------
 * 3.2. CSRF TOKEN
 * ------------------------------------------------------------ */

if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrfField')) {
    function csrfField(): string {
        $token = generateCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
}

if (!function_exists('validateCsrfToken')) {
    function validateCsrfToken(?string $token): bool {
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('verifyCsrfRequest')) {
    function verifyCsrfRequest(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        /* Ưu tiên token trong POST body */
        $token = $_POST['csrf_token'] ?? '';

        /* Thử đọc từ JSON body */
        if (empty($token)) {
            $raw = file_get_contents('php://input');
            if ($raw) {
                $json = json_decode($raw, true);
                if (is_array($json) && !empty($json['csrf_token'])) {
                    $token = $json['csrf_token'];
                }
            }
        }

        /* Thử đọc từ header */
        if (empty($token)) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        }

        if (validateCsrfToken($token)) {
            return;
        }

        /* CSRF fail */
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

        if ($isAjax) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8', true, 419);
            }
            echo json_encode([
                'success' => false,
                'message' => 'Phiên làm việc đã hết hạn hoặc yêu cầu không hợp lệ (CSRF).',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        die('<div style="font-family:Arial;padding:20px;color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;border-radius:5px;margin:20px;">
            <h3>Cảnh báo bảo mật (CSRF)!</h3>
            <p>Phiên làm việc của bạn đã hết hạn hoặc yêu cầu không hợp lệ. Vui lòng tải lại trang và thử lại.</p>
        </div>');
    }
}

/* ------------------------------------------------------------
 * 3.3. HELPER TRẢ JSON CHO API
 * ------------------------------------------------------------
 *  Return type = "never" (PHP 8.1+)
 *  → Intelephense hiểu hàm KHÔNG BAO GIỜ return
 *  → Hết cảnh báo "Possible undefined variable" sau jsonError()
 * ------------------------------------------------------------ */

if (!function_exists('jsonResponse')) {
    /**
     * Trả JSON và DỪNG script
     * @return never
     */
    function jsonResponse(array $data, int $httpCode = 200): never {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($httpCode);
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('jsonSuccess')) {
    /**
     * Trả JSON thành công và DỪNG script
     * @return never
     */
    function jsonSuccess(array $data = [], string $message = 'OK'): never {
        jsonResponse(array_merge(['success' => true, 'message' => $message], $data), 200);
    }
}

if (!function_exists('jsonError')) {
    /**
     * Trả JSON lỗi và DỪNG script
     * @return never
     */
    function jsonError(string $message, int $httpCode = 400, array $extra = []): never {
        jsonResponse(array_merge(['success' => false, 'message' => $message], $extra), $httpCode);
    }
}