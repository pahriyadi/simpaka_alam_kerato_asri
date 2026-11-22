<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/helpers/format.php';

if ($u = current_user()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $loginId  = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($loginId === '' || $password === '') {
        $error = 'Nomor HP / Username dan Password wajib diisi.';
    } else {
        $normalizedPhone = normalize_phone($loginId);
        $stmt = db()->prepare('
            SELECT * FROM users
            WHERE (no_hp = ? OR no_hp = ? OR username = ?) AND status_aktif = 1
            LIMIT 1
        ');
        $stmt->execute([$loginId, $normalizedPhone, $loginId]);
        $found = $stmt->fetch();

        if ($found && password_verify($password, $found['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$found['id'];
            log_activity('Login', 'Login berhasil sebagai ' . $found['role'], (int)$found['id']);
            set_flash('success', 'Selamat datang kembali, ' . $found['nama_lengkap'] . '!');
            redirect('index.php');
        } else {
            $error = 'Nomor HP / Username atau Password salah, atau akun tidak aktif.';
        }
    }
}

$pageTitle = 'Login';
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center mt-2">
  <div class="col-12">
    <div class="aka-card p-4">
      <div class="text-center mb-3">
        <div class="mx-auto mb-2 rounded-4 bg-white border border-success-subtle d-flex align-items-center justify-content-center p-1 shadow-sm" style="width:60px;height:60px;">
          <img src="<?= e(base_url('assets/img/logo-aka.jpg')) ?>" alt="Logo AKA" class="w-100 h-100 object-fit-contain">
        </div>
        <h1 class="h5 fw-bold text-success mb-1">Masuk ke SIMPAKA</h1>
        <p class="small text-muted mb-0">Gunakan <strong>Nomor HP Aktif</strong> (Warga) atau Username (Pengurus)</p>
      </div>

      <?php if ($error !== ''): ?>
        <div class="alert alert-danger small py-2 rounded-3"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Nomor HP Aktif / Username</label>
          <div class="input-group">
            <span class="input-group-text bg-success-subtle text-success border-success-subtle"><i class="bi bi-phone"></i></span>
            <input type="text" name="login_id" class="form-control" placeholder="Contoh: 081234567890" value="<?= e($_POST['login_id'] ?? '') ?>" required autofocus>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Password</label>
          <div class="input-group">
            <span class="input-group-text bg-success-subtle text-success border-success-subtle"><i class="bi bi-key"></i></span>
            <input type="password" name="password" class="form-control" placeholder="Masukkan password" required>
          </div>
        </div>

        <button type="submit" class="btn btn-aka w-100 py-2">
          <i class="bi bi-box-arrow-in-right me-1"></i> Masuk Sekarang
        </button>
      </form>

      <div class="aka-card-soft p-3 mt-3 small">
        <div class="fw-bold text-success mb-1"><i class="bi bi-info-circle me-1"></i> Akun Uji Coba (Password: <code>password123</code>)</div>
        <div class="text-muted">
          • Super Admin: <code>superadmin</code> / <code>081100000001</code><br>
          • Admin RT/RW: <code>adminrt</code> / <code>081100000002</code><br>
          • Warga (Blok D.17): <code>081234567890</code>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
