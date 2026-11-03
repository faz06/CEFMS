<?php
/**
 * CEFMS bootstrap: configuration, DB connection, session, error handling and helper functions.
 * Every page includes this file first.
 */
declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));
define('LOG_FILE', BASE_DIR . '/logs/app.log');
define('HOLD_MINUTES', 15);          // FR7: seat hold time
define('MAX_TICKETS', 10);           // maximum tickets per reservation

date_default_timezone_set('Asia/Kuala_Lumpur');

// ---- Configuration (XAMPP defaults; override with environment variables) -----------------
function cfg(string $key): string {
    static $c = null;
    if ($c === null) {
        $c = [
            'host' => getenv('CEFMS_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('CEFMS_DB_PORT') ?: '3306',
            'name' => getenv('CEFMS_DB_NAME') ?: 'cefms',
            'user' => getenv('CEFMS_DB_USER') ?: 'root',
            'pass' => getenv('CEFMS_DB_PASS') !== false ? getenv('CEFMS_DB_PASS') : '',
        ];
    }
    return $c[$key];
}

// ---- Logging (error detection) --------------------------------------------------------------
function log_event(string $level, string $message, array $ctx = []): void {
    $line = sprintf("[%s] %s %s%s | uri=%s | user=%s\n",
        date('Y-m-d H:i:s'), strtoupper($level), $message,
        $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE) : '',
        $_SERVER['REQUEST_URI'] ?? 'cli',
        $_SESSION['user']['userId'] ?? '-');
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    log_event('warning', "PHP error [$no] $str", ['file' => basename($file), 'line' => $line]);
    return true;
});

set_exception_handler(function (Throwable $ex): void {
    log_event('error', get_class($ex) . ': ' . $ex->getMessage(),
        ['file' => basename($ex->getFile()), 'line' => $ex->getLine()]);
    if (!headers_sent()) http_response_code(500);
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $ex->getMessage() . "\n"); return; }
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
       . '<body style="font-family:Arial;text-align:center;padding:80px;color:#1F2A5A">'
       . '<h1>Something went wrong</h1><p>The error has been recorded. Please try again later.</p>'
       . '<p><a href="/">Back to homepage</a></p></body>';
});

// ---- Database --------------------------------------------------------------------------------
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', cfg('host'), cfg('port'), cfg('name'));
        $pdo = new PDO($dsn, cfg('user'), cfg('pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ---- Session -----------------------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services.php';
require_once __DIR__ . '/layout.php';
