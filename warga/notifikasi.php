<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../helpers/notification.php';

$user = require_login();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    verify_csrf();
    mark_all_notifications_read($uid);
    set_flash('success', 'Semua notifikasi ditandai sudah dibaca.');
    redirect('warga/notifikasi.php');
}

$notifs = get_user_notifications($uid, 50);

$pageTitle = 'Notifikasi';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="aka-card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h6 fw-bold text-success mb-0"><i class="bi bi-bell-fill me-1"></i> Kotak Masuk Notifikasi</h1>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="mark_read">
      <button type="submit" class="btn btn-sm btn-aka-outline">Tandai Semua Dibaca</button>
    </form>
  </div>

  <?php if (empty($notifs)): ?>
    <p class="small text-muted text-center py-3 mb-0">Belum ada notifikasi untuk Anda.</p>
  <?php else: ?>
    <div class="d-flex flex-column gap-2">
      <?php foreach ($notifs as $n): ?>
        <?php
          $icon = match ($n['tipe']) {
              'tagihan_bulanan'       => 'bi-receipt text-warning',
              'pengeluaran_bendahara' => 'bi-cash-coin text-danger',
              'pembayaran'            => 'bi-check-circle-fill text-success',
              default                 => 'bi-megaphone-fill text-success',
          };
        ?>
        <div class="border rounded-3 p-3 <?= $n['sudah_dibaca'] ? 'bg-white' : 'aka-card-soft' ?>">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="fw-bold small">
              <i class="bi <?= $icon ?> me-1"></i> <?= e($n['judul']) ?>
              <?php if (!$n['sudah_dibaca']): ?>
                <span class="badge bg-success rounded-pill ms-1" style="font-size:0.62rem;">Baru</span>
              <?php endif; ?>
            </div>
            <small class="text-muted text-nowrap" style="font-size:0.7rem;"><?= e(tanggal_indo($n['created_at'], true)) ?></small>
          </div>
          <p class="small text-secondary mb-0 mt-1"><?= e($n['pesan']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
