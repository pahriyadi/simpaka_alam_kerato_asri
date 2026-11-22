<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
$user = require_role(['warga', 'admin', 'super_admin']);
$pdo  = db();
$uid  = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'ubah_password') {
        $lama = $_POST['password_lama'] ?? '';
        $baru = $_POST['password_baru'] ?? '';
        if (strlen($baru) < 6) {
            set_flash('danger', 'Password baru minimal 6 karakter.');
        } elseif (!password_verify($lama, $user['password_hash'])) {
            set_flash('danger', 'Password lama tidak sesuai.');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($baru, PASSWORD_DEFAULT), $uid]);
            set_flash('success', 'Password berhasil diperbarui.');
        }
        redirect('warga/profil.php');
    }

    if ($action === 'kirim_aspirasi') {
        $judul    = trim($_POST['judul'] ?? '');
        $kategori = $_POST['kategori'] ?? 'saran';
        $isi      = trim($_POST['isi'] ?? '');
        if ($judul !== '' && $isi !== '') {
            $pdo->prepare('INSERT INTO aspirasi (user_id, judul, isi, kategori) VALUES (?, ?, ?, ?)')
                ->execute([$uid, $judul, $isi, $kategori]);
            set_flash('success', 'Aspirasi / aduan Anda telah terkirim ke pengurus RT/RW.');
        }
        redirect('warga/profil.php');
    }
}

$aspirasiSaya = $pdo->prepare('SELECT * FROM aspirasi WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
$aspirasiSaya->execute([$uid]);
$aspirasiList = $aspirasiSaya->fetchAll();

$pageTitle = 'Profil & Aspirasi Warga';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="aka-card p-3">
  <h1 class="h6 fw-bold text-success mb-3"><i class="bi bi-person-badge-fill me-1"></i> Profil Saya</h1>
  <div class="row g-2 small">
    <div class="col-6">
      <div class="text-muted">Nama Lengkap</div>
      <div class="fw-bold"><?= e($user['nama_lengkap']) ?></div>
    </div>
    <div class="col-6">
      <div class="text-muted">Nomor HP (Login)</div>
      <div class="fw-bold"><?= e($user['no_hp']) ?></div>
    </div>
    <div class="col-6">
      <div class="text-muted">Blok / Nomor Rumah</div>
      <div class="fw-bold text-success"><?= $user['nama_blok'] ? 'Blok ' . e($user['nama_blok'] . '.' . $user['nomor_rumah']) : '-' ?></div>
    </div>
    <div class="col-6">
      <div class="text-muted">Status KTP / Domisili</div>
      <div class="fw-bold">
        <?= $user['status_ktp'] ? e(status_ktp_label($user['status_ktp'])) : '-' ?>
        <?= !empty($user['asal_domisili']) ? ' (' . e($user['asal_domisili']) . ')' : '' ?>
      </div>
    </div>
  </div>
</div>

<!-- Kirim Aspirasi / Aduan -->
<div class="aka-card p-3">
  <h2 class="h6 fw-bold text-success mb-2"><i class="bi bi-chat-dots-fill me-1"></i> Kirim Aspirasi / Aduan Warga</h2>
  <form method="post" class="row g-2 mb-3">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="kirim_aspirasi">
    <div class="col-8">
      <input type="text" name="judul" class="form-control form-control-sm" placeholder="Judul aduan / saran..." required>
    </div>
    <div class="col-4">
      <select name="kategori" class="form-select form-select-sm">
        <option value="saran">Saran</option>
        <option value="keluhan">Keluhan</option>
        <option value="keamanan">Keamanan</option>
        <option value="kebersihan">Kebersihan</option>
      </select>
    </div>
    <div class="col-12">
      <textarea name="isi" rows="2" class="form-control form-control-sm" placeholder="Tuliskan detail aspirasi Anda..." required></textarea>
    </div>
    <div class="col-12">
      <button type="submit" class="btn btn-sm btn-aka">Kirim ke Pengurus</button>
    </div>
  </form>

  <?php if (!empty($aspirasiList)): ?>
    <div class="small fw-bold text-muted mb-1">Riwayat Aspirasi Anda:</div>
    <?php foreach ($aspirasiList as $as): ?>
      <div class="border-top py-2 small">
        <div class="d-flex justify-content-between">
          <strong><?= e($as['judul']) ?></strong>
          <span class="badge bg-success-subtle text-success"><?= e(strtoupper($as['status'])) ?></span>
        </div>
        <?php if (!empty($as['tanggapan'])): ?>
          <div class="text-success mt-1"><i class="bi bi-reply-fill"></i> Tanggapan: <?= e($as['tanggapan']) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Ubah Password -->
<div class="aka-card p-3">
  <h2 class="h6 fw-bold text-success mb-2"><i class="bi bi-key-fill me-1"></i> Keamanan Akun (Ubah Password)</h2>
  <form method="post" class="row g-2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="ubah_password">
    <div class="col-6">
      <input type="password" name="password_lama" class="form-control form-control-sm" placeholder="Password Lama" required>
    </div>
    <div class="col-6">
      <input type="password" name="password_baru" class="form-control form-control-sm" placeholder="Password Baru (min 6)" required>
    </div>
    <div class="col-12">
      <button type="submit" class="btn btn-sm btn-aka-outline">Update Password</button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
