<?php
declare(strict_types=1);

$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$currentDir    = basename(dirname($_SERVER['PHP_SELF'] ?? ''));
$user          = current_user();
?>
<nav class="aka-bottom-nav no-print">
  <div class="aka-bottom-nav-inner">
    <a href="<?= e(base_url('index.php')) ?>"
       class="aka-nav-link <?= ($currentDir !== 'admin' && $currentDir !== 'warga' && $currentScript === 'index.php') ? 'active' : '' ?>">
      <i class="bi bi-house-door<?= ($currentDir !== 'admin' && $currentDir !== 'warga' && $currentScript === 'index.php') ? '-fill' : '' ?>"></i>
      <span>Beranda</span>
    </a>

    <?php if ($user && is_admin_or_super($user)): ?>
      <a href="<?= e(base_url('admin/keuangan.php')) ?>"
         class="aka-nav-link <?= ($currentDir === 'admin' && in_array($currentScript, ['keuangan.php', 'laporan.php'], true)) ? 'active' : '' ?>">
        <i class="bi bi-wallet2"></i>
        <span>Kas</span>
      </a>
      <a href="<?= e(base_url('admin/pembayaran.php')) ?>" class="aka-nav-fab-wrap">
        <span class="aka-nav-fab"><i class="bi bi-qr-code-scan"></i></span>
        <span>Bayar</span>
      </a>
      <a href="<?= e(base_url('admin/warga.php')) ?>"
         class="aka-nav-link <?= ($currentDir === 'admin' && $currentScript === 'warga.php') ? 'active' : '' ?>">
        <i class="bi bi-people"></i>
        <span>Warga</span>
      </a>
      <a href="#" class="aka-nav-link" data-bs-toggle="modal" data-bs-target="#menuLainnyaModal">
        <i class="bi bi-grid-3x3-gap-fill"></i>
        <span>Lainnya</span>
      </a>
    <?php elseif ($user): ?>
      <a href="<?= e(base_url('warga/riwayat.php')) ?>"
         class="aka-nav-link <?= ($currentDir === 'warga' && $currentScript === 'riwayat.php') ? 'active' : '' ?>">
        <i class="bi bi-clock-history"></i>
        <span>Riwayat</span>
      </a>
      <a href="<?= e(base_url('warga/bayar.php')) ?>" class="aka-nav-fab-wrap">
        <span class="aka-nav-fab"><i class="bi bi-qr-code-scan"></i></span>
        <span>Bayar</span>
      </a>
      <a href="<?= e(base_url('warga/kas_transparan.php')) ?>"
         class="aka-nav-link <?= ($currentDir === 'warga' && $currentScript === 'kas_transparan.php') ? 'active' : '' ?>">
        <i class="bi bi-cash-stack"></i>
        <span>Kas AKA</span>
      </a>
      <a href="<?= e(base_url('warga/profil.php')) ?>"
         class="aka-nav-link <?= ($currentDir === 'warga' && $currentScript === 'profil.php') ? 'active' : '' ?>">
        <i class="bi bi-person-circle"></i>
        <span>Profil</span>
      </a>
    <?php else: ?>
      <a href="<?= e(base_url('index.php#wilayah')) ?>" class="aka-nav-link">
        <i class="bi bi-houses"></i>
        <span>Blok</span>
      </a>
      <a href="<?= e(base_url('login.php')) ?>" class="aka-nav-fab-wrap">
        <span class="aka-nav-fab"><i class="bi bi-box-arrow-in-right"></i></span>
        <span>Login</span>
      </a>
      <a href="<?= e(base_url('index.php#pengumuman')) ?>" class="aka-nav-link">
        <i class="bi bi-megaphone"></i>
        <span>Info</span>
      </a>
      <a href="<?= e(base_url('index.php#lokasi')) ?>" class="aka-nav-link">
        <i class="bi bi-geo-alt"></i>
        <span>Lokasi</span>
      </a>
    <?php endif; ?>
  </div>
</nav>

<?php if ($user): ?>
<!-- Bottom Sheet Semua Menu ala DANA / BRImo -->
<div class="modal fade modal-bottom-sheet no-print" id="menuLainnyaModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content p-3 pb-4">
      <div class="sheet-handle"></div>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 fw-bold text-success mb-0"><i class="bi bi-grid-fill me-1"></i> Semua Layanan SIMPAKA</h2>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>

      <?php if (is_admin_or_super($user)): ?>
        <div class="quick-grid">
          <a href="<?= e(base_url('index.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-house-door-fill"></i></div>
            <span class="quick-label">Beranda</span>
          </a>
          <a href="<?= e(base_url('admin/blok_rumah.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-houses-fill"></i></div>
            <span class="quick-label">Blok Rumah</span>
          </a>
          <a href="<?= e(base_url('admin/wilayah.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-map-fill"></i></div>
            <span class="quick-label">Wilayah RT/RW</span>
          </a>
          <a href="<?= e(base_url('admin/warga.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-people-fill"></i></div>
            <span class="quick-label">Data Warga</span>
          </a>
          <a href="<?= e(base_url('admin/pembayaran.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-receipt-cutoff"></i></div>
            <span class="quick-label">Pembayaran</span>
          </a>
          <a href="<?= e(base_url('admin/keuangan.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-cash-coin"></i></div>
            <span class="quick-label">Catat Kas</span>
          </a>
          <a href="<?= e(base_url('admin/laporan.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-bar-chart-line-fill"></i></div>
            <span class="quick-label">Laporan</span>
          </a>
          <a href="<?= e(base_url('admin/cms_info.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-megaphone-fill"></i></div>
            <span class="quick-label">Info Index</span>
          </a>
          <a href="<?= e(base_url('admin/aspirasi.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-chat-square-text-fill"></i></div>
            <span class="quick-label">Aspirasi</span>
          </a>
          <?php if (is_super_admin($user)): ?>
            <a href="<?= e(base_url('admin/settings.php')) ?>" class="quick-item">
              <div class="quick-icon"><i class="bi bi-gear-fill"></i></div>
              <span class="quick-label">Settings Web</span>
            </a>
          <?php endif; ?>
          <a href="<?= e(base_url('warga/notifikasi.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-bell-fill"></i></div>
            <span class="quick-label">Notifikasi</span>
          </a>
          <a href="<?= e(base_url('warga/profil.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-person-badge-fill"></i></div>
            <span class="quick-label">Profil</span>
          </a>
          <a href="#" class="quick-item pwa-install-menu-item" onclick="event.preventDefault(); bootstrap.Modal.getOrCreateInstance(document.getElementById('menuLainnyaModal')).hide(); triggerPwaInstall();">
            <div class="quick-icon"><i class="bi bi-download"></i></div>
            <span class="quick-label">Download App</span>
          </a>
          <a href="<?= e(base_url('logout.php')) ?>" class="quick-item">
            <div class="quick-icon bg-danger-subtle text-danger"><i class="bi bi-box-arrow-right"></i></div>
            <span class="quick-label text-danger">Keluar</span>
          </a>
        </div>
      <?php else: ?>
        <div class="quick-grid">
          <a href="<?= e(base_url('index.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-house-door-fill"></i></div>
            <span class="quick-label">Beranda</span>
          </a>
          <a href="<?= e(base_url('warga/bayar.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-credit-card-fill"></i></div>
            <span class="quick-label">Bayar Iuran</span>
          </a>
          <a href="<?= e(base_url('warga/riwayat.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-clock-history"></i></div>
            <span class="quick-label">Riwayat</span>
          </a>
          <a href="<?= e(base_url('warga/kas_transparan.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-cash-stack"></i></div>
            <span class="quick-label">Kas AKA</span>
          </a>
          <a href="<?= e(base_url('admin/laporan.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-bar-chart-line-fill"></i></div>
            <span class="quick-label">Laporan Kas</span>
          </a>
          <a href="<?= e(base_url('warga/notifikasi.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-bell-fill"></i></div>
            <span class="quick-label">Notifikasi</span>
          </a>
          <a href="<?= e(base_url('warga/profil.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-person-badge-fill"></i></div>
            <span class="quick-label">Profil & Aduan</span>
          </a>
          <a href="#" class="quick-item pwa-install-menu-item" onclick="event.preventDefault(); bootstrap.Modal.getOrCreateInstance(document.getElementById('menuLainnyaModal')).hide(); triggerPwaInstall();">
            <div class="quick-icon"><i class="bi bi-download"></i></div>
            <span class="quick-label">Download App</span>
          </a>
          <a href="<?= e(base_url('logout.php')) ?>" class="quick-item">
            <div class="quick-icon bg-danger-subtle text-danger"><i class="bi bi-box-arrow-right"></i></div>
            <span class="quick-label text-danger">Keluar</span>
          </a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
