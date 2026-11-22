<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../helpers/format.php';
require_once __DIR__ . '/../helpers/notification.php';

// Jalankan pengecekan notifikasi & tagihan bulanan otomatis secara ringan
if (get_setting('auto_notif_enabled', '1') === '1') {
    try {
        run_monthly_auto_billing_and_reminder(false);
    } catch (Throwable $e) {
        // Jangan gagalkan render halaman jika auto-billing bermasalah
    }
}

$appName     = get_setting('app_name', 'SIMPAKA');
$appSubtitle = get_setting('app_subtitle', 'Sistem Informasi Manajemen Perumahan Alam Kerato Asri');
$seoDesc     = get_setting('seo_description') ?: (get_setting('sambutan_index') ?: 'Portal resmi informasi warga, pembayaran iuran, dan transparansi kas Perumahan Alam Kerato Asri (AKA) berbasis mobile.');
$ogImagePath = get_setting('og_image') ?: (get_setting('favicon_image') ?: 'assets/img/logo-aka.jpg');
$ogImageUrl  = full_url($ogImagePath);
$currentUrl  = full_url(ltrim($_SERVER['REQUEST_URI'] ?? '', '/'));
$user        = current_user();
$unreadCount = $user ? count_unread_notifications((int)$user['id']) : 0;
$pageTitle   = $pageTitle ?? $appName;
$fullTitle   = $pageTitle . ' - ' . $appName . ' (' . $appSubtitle . ')';
$flash       = get_flash();
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#05682B">
  <meta name="description" content="<?= e($seoDesc) ?>">
  <meta name="keywords" content="SIMPAKA, Perumahan Alam Kerato Asri, AKA, Manajemen Perumahan, Iuran Warga, Kas RT RW, Sumbawa">
  <meta name="author" content="albiandra-project">
  <meta name="robots" content="index, follow">
  <link rel="canonical" href="<?= e($currentUrl) ?>">

  <!-- Open Graph / WhatsApp / Facebook Share Preview -->
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="<?= e($appName) ?> - Alam Kerato Asri">
  <meta property="og:title" content="<?= e($pageTitle) ?> - <?= e($appName) ?>">
  <meta property="og:description" content="<?= e($seoDesc) ?>">
  <meta property="og:url" content="<?= e($currentUrl) ?>">
  <meta property="og:image" content="<?= e($ogImageUrl) ?>">
  <meta property="og:image:secure_url" content="<?= e($ogImageUrl) ?>">
  <meta property="og:image:alt" content="Logo <?= e($appName) ?> Alam Kerato Asri">
  <meta property="og:locale" content="id_ID">

  <!-- Twitter / X Card -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($pageTitle) ?> - <?= e($appName) ?>">
  <meta name="twitter:description" content="<?= e($seoDesc) ?>">
  <meta name="twitter:image" content="<?= e($ogImageUrl) ?>">

  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="<?= e($appName) ?>">
  <link rel="manifest" href="<?= e(base_url('manifest.json')) ?>">
  <?php $faviconUrl = base_url(get_setting('favicon_image') ?: 'assets/img/pwa-icon-192.png'); ?>
  <link rel="icon" href="<?= e($faviconUrl) ?>">
  <link rel="shortcut icon" href="<?= e($faviconUrl) ?>">
  <link rel="apple-touch-icon" href="<?= e(base_url('assets/img/pwa-icon-192.png')) ?>">
  <title><?= e($fullTitle) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= e(base_url('assets/css/simpaka.css')) ?>" rel="stylesheet">
</head>
<body>

<header class="aka-topbar">
  <div class="aka-topbar-inner">
    <a href="<?= e(base_url('index.php')) ?>" class="aka-brand">
      <div class="aka-brand-icon">
        <img src="<?= e(base_url('assets/img/logo-aka.jpg')) ?>" alt="Logo AKA">
      </div>
      <div>
        <span class="aka-brand-title"><?= e($appName) ?></span>
        <span class="aka-brand-sub">Alam Kerato Asri</span>
      </div>
    </a>

    <div class="d-flex align-items-center gap-2">
      <?php if ($user): ?>
        <?php $notifUrl = is_admin_or_super($user) ? base_url('warga/notifikasi.php') : base_url('warga/notifikasi.php'); ?>
        <a href="<?= e($notifUrl) ?>" class="btn btn-sm text-white position-relative p-1" title="Notifikasi">
          <i class="bi bi-bell-fill fs-5"></i>
          <?php if ($unreadCount > 0): ?>
            <span class="notif-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
          <?php endif; ?>
        </a>
        <a href="<?= e(base_url('warga/profil.php')) ?>"
           class="btn btn-sm text-white p-1"
           title="<?= e($user['nama_lengkap']) ?> (<?= e(role_label($user['role'])) ?>)">
          <i class="bi bi-person-circle fs-4"></i>
        </a>
      <?php else: ?>
        <a href="<?= e(base_url('login.php')) ?>" class="btn btn-sm btn-light text-success fw-semibold rounded-pill px-3">
          <i class="bi bi-box-arrow-in-right me-1"></i> Login
        </a>
      <?php endif; ?>
    </div>
  </div>
</header>

<main class="app-container">
  <!-- Banner Ajakan Download Aplikasi (Otomatis hilang jika sudah terinstall / standalone) -->
  <div id="pwaInstallBanner" class="aka-card-soft p-2 px-3 mb-3 d-none justify-content-between align-items-center gap-2 no-print">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= e(base_url('assets/img/logo-aka.jpg')) ?>" alt="SIMPAKA" class="rounded-3 bg-white p-1 border" style="width:36px;height:36px;object-fit:contain;">
      <div class="lh-sm">
        <div class="fw-bold text-success" style="font-size:0.8rem;">Download Aplikasi SIMPAKA</div>
        <div class="text-muted" style="font-size:0.68rem;">Pasang di HP Android &amp; iPhone agar lebih cepat</div>
      </div>
    </div>
    <button type="button" class="btn btn-sm btn-aka rounded-pill px-3 py-1 text-nowrap" onclick="triggerPwaInstall()" style="font-size:0.75rem;">
      <i class="bi bi-download me-1"></i> Download
    </button>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show rounded-3 shadow-sm" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
  <?php endif; ?>
