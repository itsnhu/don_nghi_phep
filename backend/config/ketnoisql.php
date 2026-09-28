<?php
/**
 * ============================================================
 *  CẤU HÌNH KẾT NỐI DATABASE MYSQL BẰNG PDO
 *  BỆNH VIỆN ĐA KHOA TÂM TRÍ ĐỒNG THÁP
 * ------------------------------------------------------------
 *  - Dùng define() cho hằng số config (tối ưu OPcache)
 *  - Singleton pattern: chỉ mở 1 kết nối PDO duy nhất
 *  - Tự động trả JSON khi request là AJAX (fetch)
 *  - Có sẵn các method tiện: fetchOne, fetchAll, insert,
 *    update, delete, transaction...
 * ============================================================
 */

/* ============================================================
 * 1. CẤU HÌNH DATABASE — dùng define() có guard
 * ============================================================ */

if (!defined('DB_HOST'))    define('DB_HOST',    'localhost');
if (!defined('DB_PORT'))    define('DB_PORT',    '3306');
if (!defined('DB_NAME'))    define('DB_NAME',    'quanlynghiphep');
if (!defined('DB_USER'))    define('DB_USER',    'root');
if (!defined('DB_PASS'))    define('DB_PASS',    '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
if (!defined('DB_COLLATE')) define('DB_COLLATE', 'utf8mb4_unicode_ci');
if (!defined('DB_TIMEZONE')) define('DB_TIMEZONE', '+07:00');

/* Môi trường: 'development' hoặc 'production' */
if (!defined('APP_ENV')) {
    define('APP_ENV', getenv('APP_ENV') ?: 'development');
}

/* ============================================================
 * 2. CLASS DATABASE — Singleton + tiện ích
 * ============================================================ */
class Database
{
    /** @var PDO|null */
    private static $instance = null;

    /**
     * Lấy kết nối PDO (Singleton)
     *
     * @throws PDOException
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
                PDO::MYSQL_ATTR_INIT_COMMAND =>
                    "SET NAMES " . DB_CHARSET
                    . " COLLATE " . DB_COLLATE
                    . ", time_zone = '" . DB_TIMEZONE . "'",
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {

                // Ghi log lỗi
                self::logError($e);

                // Nếu là request AJAX (fetch) → trả JSON
                if (self::isAjaxRequest()) {
                    if (!headers_sent()) {
                        header('Content-Type: application/json; charset=utf-8', true, 500);
                    }
                    echo json_encode([
                        'success' => false,
                        'message' => self::isDevEnv()
                            ? 'Lỗi kết nối CSDL: ' . $e->getMessage()
                            : 'Không thể kết nối cơ sở dữ liệu. Vui lòng thử lại sau.',
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                // Request thường → hiển thị trang lỗi
                if (self::isDevEnv()) {
                    die('<div style="font-family:Arial;padding:20px;color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;border-radius:5px;margin:20px;">
                        <h3>Lỗi kết nối cơ sở dữ liệu!</h3>
                        <p>' . htmlspecialchars($e->getMessage()) . '</p>
                        <p>Vui lòng kiểm tra MySQL trong XAMPP và đã import file <code>database.sql</code> chưa.</p>
                    </div>');
                } else {
                    die('Hệ thống đang bảo trì. Vui lòng quay lại sau.');
                }
            }
        }

        return self::$instance;
    }

    /* ========================================================
     * 3. CÁC METHOD TIỆN DỤNG
     * ======================================================== */

    /**
     * Thực thi câu SQL (INSERT/UPDATE/DELETE)
     * @return int Số dòng bị ảnh hưởng
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Lấy 1 dòng
     * @return array|null
     */
    public static function fetchOne(string $sql, array $params = [])
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Lấy nhiều dòng
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Lấy 1 giá trị đơn (cột đầu tiên của dòng đầu tiên)
     */
    public static function fetchColumn(string $sql, array $params = [])
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val === false ? null : $val;
    }

    /**
     * INSERT — trả về ID vừa chèn
     */
    public static function insert(string $table, array $data): int
    {
        $cols   = array_keys($data);
        $places = array_map(fn($c) => ':' . $c, $cols);

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`,`', $cols),
            implode(',', $places)
        );

        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($data);
        return (int) self::getConnection()->lastInsertId();
    }

    /**
     * UPDATE
     * @return int Số dòng bị ảnh hưởng
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = [];
        foreach (array_keys($data) as $c) {
            $set[] = "`$c` = :$c";
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $set),
            $where
        );

        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute(array_merge($data, $whereParams));
        return $stmt->rowCount();
    }

    /**
     * DELETE
     * @return int Số dòng bị xóa
     */
    public static function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, $where);
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /* ========================================================
     * 4. TRANSACTION
     * ======================================================== */
    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    public static function rollBack(): bool
    {
        return self::getConnection()->rollBack();
    }

    /* ========================================================
     * 5. HELPER NỘI BỘ
     * ======================================================== */

    /** Phát hiện request AJAX (fetch/XHR) */
    private static function isAjaxRequest(): bool
    {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        return stripos($ct, 'application/json') !== false;
    }

    /** Có đang ở môi trường dev không */
    private static function isDevEnv(): bool
    {
        return APP_ENV === 'development';
    }

    /** Ghi log lỗi vào file */
    private static function logError(Throwable $e): void
    {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $msg = sprintf(
            "[%s] [DB ERROR] %s in %s:%d\n%s\n",
            date('Y-m-d H:i:s'),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        @error_log($msg, 3, $logDir . '/db_error.log');
    }

    /** Ngăn clone & unserialize (giữ Singleton thật chặt) */
    private function __clone() {}
    public function __wakeup()
    {
        throw new Exception('Cannot unserialize Singleton.');
    }
}