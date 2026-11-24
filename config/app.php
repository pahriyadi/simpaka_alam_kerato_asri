<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Makassar');

// Deteksi base URL otomatis agar berjalan di subfolder mana pun (misal /SIMPAKA)
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$docRoot    = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
$appRoot    = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: '');

$basePath = '';
if ($docRoot !== '' && str_starts_with($appRoot, $docRoot)) {
    $basePath = substr($appRoot, strlen($docRoot));
}
$basePath = rtrim($basePath, '/');

define('BASE_PATH', $basePath);
define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', APP_ROOT . '/assets/uploads');
define('APP_VERSION', 'v1.4.0');

function app_version_label(): string
{
    $ver = APP_VERSION;
    $headFile = APP_ROOT . '/.git/HEAD';
    if (is_readable($headFile)) {
        $head = trim((string)@file_get_contents($headFile));
        if (str_starts_with($head, 'ref: ')) {
            $refFile = APP_ROOT . '/.git/' . substr($head, 5);
            if (is_readable($refFile)) {
                $hash = trim((string)@file_get_contents($refFile));
                if ($hash !== '') {
                    $ver .= ' (' . substr($hash, 0, 7) . ')';
                }
            }
        } elseif ($head !== '') {
            $ver .= ' (' . substr($head, 0, 7) . ')';
        }
    }
    return $ver;
}

function base_url(string $path = ''): string
{
    $path = ltrim($path, '/');

    // Hilangkan ekstensi .php dan index.php agar semua URL bersih
    if ($path !== '') {
        $path = preg_replace('/^index\.php(?=$|[?#])/', '', $path) ?? $path;
        $path = preg_replace('/\.php(?=$|[?#])/', '', $path) ?? $path;
    }

    if ($path === '') {
        return BASE_PATH . '/';
    }
    if (str_starts_with($path, '#') || str_starts_with($path, '?')) {
        return BASE_PATH . '/' . $path;
    }
    return BASE_PATH . '/' . $path;
}

function full_url(string $path = ''): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
    $scheme = $isHttps ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'simpaka.my.id';
    return $scheme . '://' . $host . base_url($path);
}

function redirect(string $path): never
{
    header('Location: ' . base_url($path));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Permintaan tidak valid (CSRF token mismatch). Silakan muat ulang halaman.');
    }
}

function set_flash(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['_flash'])) {
        return null;
    }
    $flash = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $flash;
}
