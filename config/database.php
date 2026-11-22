<?php
declare(strict_types=1);

if (file_exists(__DIR__ . '/database.local.php')) {
    require_once __DIR__ . '/database.local.php';
}

if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'simpaka_db');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        die('<div style="font-family:sans-serif;padding:24px;max-width:600px;margin:40px auto;border:1px solid #c8e6c9;border-radius:12px;background:#f1f8e9;">
            <h2 style="color:#1b5e20;margin-top:0;">Koneksi Database SIMPAKA Gagal</h2>
            <p>Pastikan MySQL di XAMPP sudah aktif dan file <code>database.sql</code> sudah di-import ke database <strong>simpaka_db</strong>.</p>
            <small style="color:#555;">Detail: ' . htmlspecialchars($e->getMessage()) . '</small>
        </div>');
    }

    return $pdo;
}

function get_all_settings(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }

    $stmt = db()->query('SELECT setting_key, setting_value FROM settings');
    $cache = [];
    foreach ($stmt->fetchAll() as $row) {
        $cache[$row['setting_key']] = $row['setting_value'];
    }
    return $cache;
}

function get_setting(string $key, string $default = ''): string
{
    $all = get_all_settings();
    return $all[$key] ?? $default;
}

function set_setting(string $key, ?string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
    get_all_settings(true);
}

function get_rt_list(): array
{
    return db()->query('SELECT DISTINCT rt FROM referensi_rt_rw WHERE aktif = 1 ORDER BY rt ASC')->fetchAll(PDO::FETCH_COLUMN);
}

function get_rw_list(): array
{
    return db()->query('SELECT DISTINCT rw FROM referensi_rt_rw WHERE aktif = 1 ORDER BY rw ASC')->fetchAll(PDO::FETCH_COLUMN);
}

function sync_cakupan_blok_rtrw(string $rt, string $rw): void
{
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT kode_blok, nomor_blok FROM referensi_blok WHERE rt = ? AND rw = ? ORDER BY kode_blok ASC, nomor_blok ASC');
    $stmt->execute([$rt, $rw]);
    $rows = $stmt->fetchAll();

    $parts = [];
    foreach ($rows as $r) {
        $parts[] = $r['kode_blok'] . '.' . $r['nomor_blok'];
    }
    $summary = implode(', ', $parts);

    $pdo->prepare('UPDATE referensi_rt_rw SET cakupan_blok = ? WHERE rt = ? AND rw = ?')->execute([$summary, $rt, $rw]);
}
