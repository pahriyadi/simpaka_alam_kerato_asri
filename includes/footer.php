<?php
declare(strict_types=1);

$footerBranding = get_setting('footer_branding', 'albiandra-project (perumahan alam kerato asri blok D.17)');
$appName        = get_setting('app_name', 'SIMPAKA');
?>
  <footer class="aka-footer">
    <div>&copy; <?= date('Y') ?> <strong><?= e($appName) ?></strong></div>
    <div><?= e($footerBranding) ?></div>
  </footer>
</main>

<?php require __DIR__ . '/bottom_nav.php'; ?>

<!-- Modal Download & Tutorial Install Android / iPhone -->
<div class="modal fade no-print" id="installAppModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="h6 modal-title"><i class="bi bi-phone-fill me-1"></i> Download Aplikasi SIMPAKA</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="pwaNativeBtnWrap" class="mb-3 d-none">
          <button type="button" class="btn btn-aka w-100 py-2" onclick="installViaNativePrompt()">
            <i class="bi bi-download me-1"></i> Install Sekarang (1 Klik)
          </button>
        </div>

        <div id="pwaAndroidGuide" class="aka-card-soft p-3 mb-2 small">
          <div class="fw-bold text-success mb-1"><i class="bi bi-android2 me-1"></i> Tutorial Android (Chrome / Edge):</div>
          <ol class="mb-0 ps-3">
            <li>Buka website SIMPAKA menggunakan <strong>Google Chrome</strong>.</li>
            <li>Ketuk ikon titik tiga (<strong>&#8942;</strong>) di pojok kanan atas browser.</li>
            <li>Pilih <strong>&ldquo;Tambahkan ke Layar Utama&rdquo;</strong> atau <strong>&ldquo;Install App&rdquo;</strong>.</li>
            <li>Ketuk <strong>Install / Tambahkan</strong>, ikon SIMPAKA akan muncul di layar HP Anda.</li>
          </ol>
        </div>

        <div id="pwaIosGuide" class="border rounded-3 p-3 small bg-light-subtle">
          <div class="fw-bold text-dark mb-1"><i class="bi bi-apple me-1"></i> Tutorial iPhone / iPad (Safari):</div>
          <ol class="mb-0 ps-3">
            <li>Buka website SIMPAKA menggunakan browser <strong>Safari</strong>.</li>
            <li>Ketuk ikon <strong>Bagikan / Share</strong> (<i class="bi bi-box-arrow-up"></i>) di bagian bawah layar.</li>
            <li>Gulir ke bawah lalu pilih <strong>&ldquo;Tambahkan ke Layar Utama&rdquo;</strong> (<em>Add to Home Screen</em>).</li>
            <li>Ketuk <strong>Tambah (Add)</strong> di pojok kanan atas.</li>
          </ol>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-sm btn-outline-success" onclick="markPwaInstalled()">
          <i class="bi bi-check-circle me-1"></i> Saya Sudah Download
        </button>
        <button type="button" class="btn btn-sm btn-aka px-3" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(base_url('assets/js/simpaka.js')) ?>"></script>
<script>
let deferredPwaPrompt = null;
const isStandaloneMode = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
const isAndroidDevice  = /Android/i.test(navigator.userAgent);
const isIosDevice      = /iPhone|iPad|iPod/i.test(navigator.userAgent);

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('<?= e(base_url('sw.js')) ?>').then((reg) => {
    window.simpakaSwReg = reg;
    <?php if (current_user()): ?>
      initMobilePushNotifications(reg);
    <?php endif; ?>
  }).catch(() => {});
}

<?php if (current_user()): ?>
function initMobilePushNotifications(swReg) {
  if (!('Notification' in window)) return;

  // Minta izin notifikasi HP secara halus saat sentuhan pertama jika status masih default
  if (Notification.permission === 'default' && !sessionStorage.getItem('simpaka_notif_asked')) {
    const askPerm = () => {
      sessionStorage.setItem('simpaka_notif_asked', '1');
      Notification.requestPermission().then((perm) => {
        if (perm === 'granted') pollNewNotifications(swReg);
      });
    };
    document.addEventListener('pointerdown', askPerm, { once: true });
  } else if (Notification.permission === 'granted') {
    pollNewNotifications(swReg);
    setInterval(() => pollNewNotifications(swReg), 20000);
  }
}

function pollNewNotifications(swReg) {
  const lastId = parseInt(localStorage.getItem('simpaka_last_notif_id') || '0', 10);
  fetch('<?= e(base_url('api/notif_poll.php')) ?>?last_id=' + lastId)
    .then(r => r.json())
    .then(res => {
      if (!res || !Array.isArray(res.items) || res.items.length === 0) return;
      let maxId = lastId;
      res.items.forEach(item => {
        const nid = parseInt(item.id, 10);
        if (nid > maxId) maxId = nid;
        if (lastId > 0 && swReg && swReg.showNotification) {
          swReg.showNotification(item.judul, {
            body: item.pesan,
            icon: '<?= e(base_url('assets/img/logo-aka.jpg')) ?>',
            badge: '<?= e(base_url('assets/img/logo-aka.jpg')) ?>',
            vibrate: [200, 100, 200],
            data: { url: '<?= e(base_url('warga/notifikasi.php')) ?>' }
          });
        }
      });
      localStorage.setItem('simpaka_last_notif_id', String(maxId));
    })
    .catch(() => {});
}
<?php endif; ?>

function updatePwaInstallUI() {
  const hide = isStandaloneMode || localStorage.getItem('simpaka_installed') === '1';
  const banner = document.getElementById('pwaInstallBanner');
  if (banner) {
    banner.classList.toggle('d-none', hide);
    banner.classList.toggle('d-flex', !hide);
  }
  document.querySelectorAll('.pwa-install-menu-item').forEach(el => {
    el.style.display = hide ? 'none' : '';
  });
  // Sesuaikan tampilan panduan berdasarkan perangkat
  const androidGuide = document.getElementById('pwaAndroidGuide');
  const iosGuide = document.getElementById('pwaIosGuide');
  if (androidGuide && iosGuide) {
    if (isAndroidDevice) iosGuide.classList.add('d-none');
    if (isIosDevice) androidGuide.classList.add('d-none');
  }
}

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPwaPrompt = e;
  const wrap = document.getElementById('pwaNativeBtnWrap');
  if (wrap) wrap.classList.remove('d-none');
  updatePwaInstallUI();

  // Di Android, langsung picu dialog install otomatis pada sentuhan pertama jika belum terpasang
  const alreadyInstalled = isStandaloneMode || localStorage.getItem('simpaka_installed') === '1';
  if (isAndroidDevice && !alreadyInstalled && !sessionStorage.getItem('simpaka_prompted')) {
    const autoTriggerOnTap = () => {
      sessionStorage.setItem('simpaka_prompted', '1');
      if (deferredPwaPrompt) {
        installViaNativePrompt();
      }
    };
    document.addEventListener('pointerdown', autoTriggerOnTap, { once: true });
  }
});

window.addEventListener('appinstalled', () => {
  localStorage.setItem('simpaka_installed', '1');
  deferredPwaPrompt = null;
  updatePwaInstallUI();
});

function installViaNativePrompt() {
  if (!deferredPwaPrompt) return;
  deferredPwaPrompt.prompt();
  deferredPwaPrompt.userChoice.then((res) => {
    if (res.outcome === 'accepted') {
      localStorage.setItem('simpaka_installed', '1');
      updatePwaInstallUI();
      const modalEl = document.getElementById('installAppModal');
      if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    }
    deferredPwaPrompt = null;
  });
}

function triggerPwaInstall() {
  if (deferredPwaPrompt) {
    installViaNativePrompt();
    return;
  }
  bootstrap.Modal.getOrCreateInstance(document.getElementById('installAppModal')).show();
}

function markPwaInstalled() {
  localStorage.setItem('simpaka_installed', '1');
  updatePwaInstallUI();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('installAppModal')).hide();
}

document.addEventListener('DOMContentLoaded', updatePwaInstallUI);
</script>
</body>
</html>
