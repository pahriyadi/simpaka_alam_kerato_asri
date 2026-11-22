<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../helpers/format.php';

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $cachedUser = null;
    if ($cachedUser !== null && (int)$cachedUser['id'] === (int)$_SESSION['user_id']) {
        return $cachedUser;
    }

    $stmt = db()->prepare('
        SELECT u.*, wp.blok_rumah_id, wp.status_ktp, wp.asal_domisili, wp.status_penghuni,
               b.nama_blok, b.nomor_rumah
        FROM users u
        LEFT JOIN warga_profil wp ON wp.user_id = u.id
        LEFT JOIN blok_rumah b ON b.id = wp.blok_rumah_id
        WHERE u.id = ? AND u.status_aktif = 1
        LIMIT 1
    ');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    $cachedUser = $user;
    return $cachedUser;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        set_flash('warning', 'Silakan login terlebih dahulu.');
        redirect('login.php');
    }
    return $user;
}

function require_role(array $allowedRoles): array
{
    $user = require_login();
    if (!in_array($user['role'], $allowedRoles, true)) {
        set_flash('danger', 'Anda tidak memiliki akses ke halaman tersebut.');
        redirect('index.php');
    }
    return $user;
}

function is_super_admin(?array $user = null): bool
{
    $u = $user ?? current_user();
    return $u !== null && $u['role'] === 'super_admin';
}

function is_admin_or_super(?array $user = null): bool
{
    $u = $user ?? current_user();
    return $u !== null && in_array($u['role'], ['super_admin', 'admin'], true);
}

function log_activity(string $aksi, ?string $detail = null, ?int $userId = null): void
{
    $uid = $userId ?? ($_SESSION['user_id'] ?? null);
    try {
        $stmt = db()->prepare('INSERT INTO log_aktivitas (user_id, aksi, detail) VALUES (?, ?, ?)');
        $stmt->execute([$uid, $aksi, $detail]);
    } catch (Throwable $e) {
        // Abaikan error log agar tidak mengganggu alur utama
    }
}
