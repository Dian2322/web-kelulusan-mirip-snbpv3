<?php
// config.php - database configuration
$appTimezone = getenv('APP_TIMEZONE') ?: 'Asia/Jakarta';
if (is_string($appTimezone) && $appTimezone !== '') {
    try {
        date_default_timezone_set($appTimezone);
    } catch (Throwable $e) {
        date_default_timezone_set('Asia/Jakarta');
    }
}

$appDebug = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('display_startup_errors', $appDebug ? '1' : '0');
error_reporting($appDebug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

// Ensure session cookies work correctly on shared hosting (cPanel) and subfolders.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $rawHost = $_SERVER['HTTP_HOST'] ?? '';
    $hostOnly = preg_replace('/:\d+$/', '', (string)$rawHost);
    $isLocalHost = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);
    $hasDotDomain = (strpos($hostOnly, '.') !== false);
    $cookieDomain = (!$isLocalHost && $hasDotDomain) ? $hostOnly : '';

    if (PHP_VERSION_ID >= 70300) {
        $cookieParams = [
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if ($cookieDomain !== '') {
            $cookieParams['domain'] = $cookieDomain;
        }
        session_set_cookie_params($cookieParams);
    } else {
        // For older PHP versions, only set the core params (samesite unsupported).
        session_set_cookie_params(0, '/', $cookieDomain, $secure, true);
    }

    session_start();
}

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; script-src 'self' 'unsafe-inline' https://code.jquery.com https://cdn.jsdelivr.net; font-src 'self' data: https://cdnjs.cloudflare.com; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

// Database configuration - adjust for hosting environment
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'belajarinfotmati_pengumuman';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    if ($appDebug) {
        die("Database connection failed: " . htmlspecialchars($e->getMessage()) . ". Please check your database credentials in config.php.");
    }
    die('Database connection failed. Please contact administrator.');
}

function is_admin_logged_in() {
    return isset($_SESSION['admin_id']);
}

function require_admin() {
    if (!is_admin_logged_in()) {
        // Use absolute path for redirects on hosting
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = dirname($_SERVER['SCRIPT_NAME']);
        $login_url = $protocol . '://' . $host . $script . '/login.php';
        header('Location: ' . $login_url);
        exit;
    }
}

function csrf_token() {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_validate($token) {
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_require_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!csrf_validate($token)) {
        http_response_code(419);
        exit('Permintaan tidak valid (CSRF token). Muat ulang halaman lalu coba lagi.');
    }
    return true;
}

function admin_login_rate_limited($username) {
    $username = strtolower(trim((string)$username));
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login_rate_' . sha1($ip . '|' . $username);
    $bucket = $_SESSION[$key] ?? ['fails' => 0, 'until' => 0];
    $now = time();

    if (!is_array($bucket)) {
        $bucket = ['fails' => 0, 'until' => 0];
    }
    if (($bucket['until'] ?? 0) > $now) {
        return (int)$bucket['until'];
    }
    return 0;
}

function admin_login_register_failure($username) {
    $username = strtolower(trim((string)$username));
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login_rate_' . sha1($ip . '|' . $username);
    $bucket = $_SESSION[$key] ?? ['fails' => 0, 'until' => 0];
    $now = time();

    $fails = (int)($bucket['fails'] ?? 0) + 1;
    $delay = min(300, 2 ** min($fails, 8));
    $_SESSION[$key] = [
        'fails' => $fails,
        'until' => $now + $delay,
    ];
}

function admin_login_reset_failures($username) {
    $username = strtolower(trim((string)$username));
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login_rate_' . sha1($ip . '|' . $username);
    unset($_SESSION[$key]);
}

function ensure_settings_table(PDO $pdo) {
    static $initialized = false;

    if ($initialized) {
        return true;
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `settings` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(50) NOT NULL UNIQUE,
                `value` TEXT DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        try {
            $pdo->exec("ALTER TABLE `settings` MODIFY COLUMN `value` TEXT DEFAULT NULL");
        } catch (\Throwable $e) {
            // Keep existing schema if ALTER is unsupported.
        }

        $defaults = [
            'announcement_time' => '',
            'announcement_timezone' => 'Asia/Jakarta',
            'logo' => 'logo.png',
            'background' => '',
            'skl_link' => '',
            'skl_label' => 'Download SKL.Pdf',
            'result_info_note' => '',
            'result_info_note_color' => '#f5f8ff',
            'result_info_note_icon' => 'fas fa-circle-info'
        ];

        $stmt = $pdo->prepare(
            "INSERT INTO settings (name, value)
             VALUES (:name, :value)
             ON DUPLICATE KEY UPDATE value = COALESCE(value, VALUES(value))"
        );

        foreach ($defaults as $name => $value) {
            $stmt->execute([
                'name' => $name,
                'value' => $value
            ]);
        }

        $initialized = true;
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
