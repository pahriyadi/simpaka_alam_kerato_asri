<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../helpers/upload.php';
$user = require_role(['super_admin']);
$pdo  = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $keys = [
            'app_name', 'app_subtitle', 'nama_perumahan', 'info_rekening',
            'footer_branding', 'auto_notif_day', 'wa_gateway_url', 'wa_gateway_token',
            'onesignal_app_id', 'onesignal_api_key'
        ];
        foreach ($keys as $k) {
            set_setting($k, trim($_POST[$k] ?? ''));
        }
        set_setting('tampilkan_kas_publik', isset($_POST['tampilkan_kas_publik']) ? '1' : '0');
        set_setting('auto_notif_enabled', isset($_POST['auto_notif_enabled']) ? '1' : '0');
        set_setting('allow_bayar_bulanan', isset($_POST['allow_bayar_bulanan']) ? '1' : '0');

        try {
            $qrisPath = handle_image_upload($_FILES['qris_image'] ?? [], 'qris');
            if ($qrisPath) {
                set_setting('qris_image', $qrisPath);
            }
            $favPath = handle_image_upload($_FILES['favicon_image'] ?? [], 'branding');
            if ($favPath) {
                set_setting('favicon_image', $favPath);
            }
        } catch (Throwable $e) {
            set_flash('danger', $e->getMessage());
            redirect('admin/settings.php');
        }

        log_activity('Update Settings Website', 'Konfigurasi sistem & footer branding diperbarui');
        set_flash('success', 'Seluruh pengaturan website & backend berhasil disimpan.');
        redirect('admin/settings.php');
    }

    if ($action === 'save_iuran') {
        $id      = (int)($_POST['id'] ?? 0);
        $nama    = trim($_POST['nama_iuran'] ?? '');
        $nominal = (float)($_POST['nominal_default'] ?? 0);
        $periode = $_POST['periode'] ?? 'bulanan';
        if ($nama !== '' && $nominal > 0) {
            if ($id > 0) {
                $pdo->prepare('UPDATE jenis_iuran SET nama_iuran = ?, nominal_default = ?, periode = ? WHERE id = ?')
                    ->execute([$nama, $nominal, $periode, $id]);
                set_flash('success', "Jenis iuran {$nama} diperbarui.");
            } else {
                $pdo->prepare('INSERT INTO jenis_iuran (nama_iuran, nominal_default, periode, aktif) VALUES (?, ?, ?, 1)')
                    ->execute([$nama, $nominal, $periode]);
                set_flash('success', "Jenis iuran {$nama} ditambahkan.");
            }
            log_activity('Simpan Jenis Iuran', $nama);
        }
        redirect('admin/settings.php');
    }

    if ($action === 'toggle_iuran') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE jenis_iuran SET aktif = 1 - aktif WHERE id = ?')->execute([$id]);
            set_flash('success', 'Status jenis iuran diubah.');
        }
        redirect('admin/settings.php');
    }

    if ($action === 'delete_iuran') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $pdo->prepare('DELETE FROM jenis_iuran WHERE id = ?')->execute([$id]);
                set_flash('success', 'Jenis iuran dihapus.');
            } catch (PDOException $e) {
                set_flash('danger', 'Jenis iuran tidak dapat dihapus karena sudah dipakai di transaksi pembayaran.');
            }
        }
        redirect('admin/settings.php');
    }

    if ($action === 'save_admin') {
        $id       = (int)($_POST['id'] ?? 0);
        $nama     = trim($_POST['nama_lengkap'] ?? '');
        $noHp     = normalize_phone($_POST['no_hp'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $role     = in_array($_POST['role'] ?? '', ['super_admin', 'admin'], true) ? $_POST['role'] : 'admin';
        $rt       = trim($_POST['rt'] ?? '');
        $rw       = trim($_POST['rw'] ?? '');
        $pass     = $_POST['password'] ?? '';

        $rtVal = ($role === 'admin' && $rt !== '') ? str_pad($rt, 2, '0', STR_PAD_LEFT) : null;
        $rwVal = ($role === 'admin' && $rw !== '') ? str_pad($rw, 2, '0', STR_PAD_LEFT) : null;

        if ($nama !== '' && $noHp !== '') {
            try {
                if ($id > 0) {
                    $pdo->prepare('UPDATE users SET nama_lengkap = ?, no_hp = ?, username = ?, role = ?, rt = ?, rw = ? WHERE id = ?')
                        ->execute([$nama, $noHp, $username !== '' ? $username : null, $role, $rtVal, $rwVal, $id]);
                    if ($pass !== '') {
                        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                            ->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
                    }
                    set_flash('success', "Akun pengurus {$nama} diperbarui.");
                } else {
                    $pwdHash = password_hash($pass !== '' ? $pass : 'password123', PASSWORD_DEFAULT);
                    $pdo->prepare('INSERT INTO users (nama_lengkap, no_hp, username, password_hash, role, rt, rw, status_aktif) VALUES (?, ?, ?, ?, ?, ?, ?, 1)')
                        ->execute([$nama, $noHp, $username !== '' ? $username : null, $pwdHash, $role, $rtVal, $rwVal]);
                    set_flash('success', "Akun {$role} untuk {$nama} berhasil dibuat.");
                }
                log_activity('Simpan Pengurus', "{$nama} ({$role})");
            } catch (PDOException $e) {
                set_flash('danger', 'No. HP atau Username sudah terdaftar.');
            }
        }
        redirect('admin/settings.php');
    }

    if ($action === 'delete_admin') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && $id !== (int)$user['id']) {
            $pdo->prepare("DELETE FROM users WHERE id = ? AND role IN ('super_admin', 'admin')")->execute([$id]);
            set_flash('success', 'Akun pengurus dihapus.');
        } else {
            set_flash('danger', 'Tidak bisa menghapus akun yang sedang aktif digunakan.');
        }
        redirect('admin/settings.php');
    }

    if ($action === 'save_rtrw') {
        $id  = (int)($_POST['id'] ?? 0);
        $rt  = str_pad(trim($_POST['rt'] ?? ''), 2, '0', STR_PAD_LEFT);
        $rw  = str_pad(trim($_POST['rw'] ?? ''), 2, '0', STR_PAD_LEFT);
        $ket = trim($_POST['keterangan'] ?? '');
        if ($rt !== '' && $rw !== '') {
            try {
                if ($id > 0) {
                    $pdo->prepare('UPDATE referensi_rt_rw SET rt = ?, rw = ?, keterangan = ? WHERE id = ?')->execute([$rt, $rw, $ket, $id]);
                    set_flash('success', "Referensi RT {$rt} / RW {$rw} diperbarui.");
                } else {
                    $pdo->prepare('INSERT INTO referensi_rt_rw (rt, rw, keterangan, aktif) VALUES (?, ?, ?, 1)')->execute([$rt, $rw, $ket]);
                    set_flash('success', "Referensi RT {$rt} / RW {$rw} ditambahkan.");
                }
                sync_cakupan_blok_rtrw($rt, $rw);
                log_activity('Simpan Referensi RT/RW', "RT {$rt} / RW {$rw}");
            } catch (PDOException $e) {
                set_flash('danger', "Referensi RT {$rt} / RW {$rw} sudah ada.");
            }
        }
        redirect('admin/settings.php');
    }

    if ($action === 'delete_rtrw') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM referensi_rt_rw WHERE id = ?')->execute([$id]);
            set_flash('success', 'Referensi RT/RW dihapus.');
        }
        redirect('admin/settings.php');
    }

    if ($action === 'save_struktur') {
        $id      = (int)($_POST['id'] ?? 0);
        $nama    = trim($_POST['nama'] ?? '');
        $jabatan = trim($_POST['jabatan'] ?? '');
        $rt      = trim($_POST['rt'] ?? '');
        $rw      = trim($_POST['rw'] ?? '');
        $noHp    = normalize_phone($_POST['no_hp'] ?? '');
        $urutan  = (int)($_POST['urutan'] ?? 1);

        $rtVal = $rt !== '' ? str_pad($rt, 2, '0', STR_PAD_LEFT) : null;
        $rwVal = $rw !== '' ? str_pad($rw, 2, '0', STR_PAD_LEFT) : null;

        if ($nama !== '' && $jabatan !== '') {
            try {
                $foto = handle_image_upload($_FILES['foto'] ?? [], 'pengurus');
                if ($id > 0) {
                    if ($foto) {
                        $pdo->prepare('UPDATE struktur_organisasi SET nama = ?, jabatan = ?, rt = ?, rw = ?, no_hp = ?, foto = ?, urutan = ? WHERE id = ?')
                            ->execute([$nama, $jabatan, $rtVal, $rwVal, $noHp, $foto, $urutan, $id]);
                    } else {
                        $pdo->prepare('UPDATE struktur_organisasi SET nama = ?, jabatan = ?, rt = ?, rw = ?, no_hp = ?, urutan = ? WHERE id = ?')
                            ->execute([$nama, $jabatan, $rtVal, $rwVal, $noHp, $urutan, $id]);
                    }
                    set_flash('success', 'Struktur pengurus diperbarui.');
                } else {
                    $pdo->prepare('INSERT INTO struktur_organisasi (nama, jabatan, rt, rw, no_hp, foto, urutan) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$nama, $jabatan, $rtVal, $rwVal, $noHp, $foto, $urutan]);
                    set_flash('success', 'Pengurus ditambahkan ke Struktur Organisasi.');
                }
                log_activity('Kelola Struktur Pengurus', "{$nama} - {$jabatan}");
            } catch (Throwable $e) {
                set_flash('danger', $e->getMessage());
            }
        }
        redirect('admin/settings.php');
    }

    if ($action === 'delete_struktur') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM struktur_organisasi WHERE id = ?')->execute([$id]);
            set_flash('success', 'Pengurus dihapus dari Struktur Organisasi.');
        }
        redirect('admin/settings.php');
    }
}

$iuranList    = $pdo->query('SELECT * FROM jenis_iuran ORDER BY id ASC')->fetchAll();
$rtrwList     = $pdo->query('SELECT * FROM referensi_rt_rw ORDER BY rw ASC, rt ASC')->fetchAll();
$strukturList = $pdo->query('SELECT * FROM struktur_organisasi ORDER BY urutan ASC, id ASC')->fetchAll();
$rtOptions = get_rt_list();
$rwOptions = get_rw_list();
$adminList = $pdo->query("SELECT id, nama_lengkap, no_hp, username, role, rt, rw FROM users WHERE role IN ('super_admin', 'admin') ORDER BY role ASC, id ASC")->fetchAll();
$logs      = $pdo->query('
    SELECT l.*, u.nama_lengkap
    FROM log_aktivitas l
    LEFT JOIN users u ON u.id = l.user_id
    ORDER BY l.created_at DESC
    LIMIT 10
')->fetchAll();

$pageTitle = 'Settings Website (Super Admin)';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin_nav.php';
?>

<div class="aka-card p-3">
  <h1 class="h6 fw-bold text-success mb-3"><i class="bi bi-gear-fill me-1"></i> Pengaturan Website & Backend (Super Admin)</h1>
  <form method="post" enctype="multipart/form-data" class="row g-2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_settings">

    <div class="col-6 col-md-4">
      <label class="form-label small fw-semibold">Nama Aplikasi</label>
      <input type="text" name="app_name" class="form-control form-control-sm" value="<?= e(get_setting('app_name', 'SIMPAKA')) ?>">
    </div>
    <div class="col-6 col-md-8">
      <label class="form-label small fw-semibold">Nama Perumahan</label>
      <input type="text" name="nama_perumahan" class="form-control form-control-sm" value="<?= e(get_setting('nama_perumahan')) ?>">
    </div>
    <div class="col-12">
      <label class="form-label small fw-semibold">Subjudul Aplikasi</label>
      <input type="text" name="app_subtitle" class="form-control form-control-sm" value="<?= e(get_setting('app_subtitle')) ?>">
    </div>
    <div class="col-12">
      <label class="form-label small fw-semibold text-success">Footer Branding (Bisa diatur dari backend)</label>
      <input type="text" name="footer_branding" class="form-control form-control-sm" value="<?= e(get_setting('footer_branding', 'albiandra-project (perumahan alam kerato asri blok D.17)')) ?>">
    </div>
    <div class="col-12 col-md-7">
      <label class="form-label small fw-semibold">Informasi Rekening / E-Wallet Bendahara</label>
      <textarea name="info_rekening" rows="3" class="form-control form-control-sm"><?= e(get_setting('info_rekening')) ?></textarea>
    </div>
    <div class="col-12 col-md-5">
      <label class="form-label small fw-semibold">Upload Gambar QRIS Bendahara</label>
      <input type="file" name="qris_image" class="form-control form-control-sm" accept="image/*">
      <?php if ($qris = get_setting('qris_image')): ?>
        <small class="text-success d-block mt-1"><i class="bi bi-check-circle-fill"></i> QRIS sudah terpasang (<a href="<?= e(base_url($qris)) ?>" target="_blank">Lihat</a>)</small>
      <?php endif; ?>
    </div>
    <div class="col-12">
      <label class="form-label small fw-semibold">Upload Favicon Website (PNG / JPG / ICO)</label>
      <div class="d-flex align-items-center gap-2">
        <?php $curFav = get_setting('favicon_image') ?: 'assets/img/logo-aka.jpg'; ?>
        <img src="<?= e(base_url($curFav)) ?>" alt="Favicon" class="rounded border bg-white p-1" style="width:36px;height:36px;object-fit:contain;">
        <input type="file" name="favicon_image" class="form-control form-control-sm" accept="image/*,.ico">
      </div>
    </div>

    <hr class="my-2">
    <div class="col-12 fw-bold small text-success">Notifikasi Otomatis Bulanan & WhatsApp Gateway</div>
    <div class="col-4 col-md-3">
      <label class="form-label small fw-semibold">Tgl Tagihan (1-28)</label>
      <input type="number" name="auto_notif_day" min="1" max="28" class="form-control form-control-sm" value="<?= e(get_setting('auto_notif_day', '1')) ?>">
    </div>
    <div class="col-8 col-md-5">
      <label class="form-label small fw-semibold">URL API WhatsApp Gateway</label>
      <input type="url" name="wa_gateway_url" class="form-control form-control-sm" placeholder="https://api.fonnte.com/send" value="<?= e(get_setting('wa_gateway_url')) ?>">
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label small fw-semibold">Token API WhatsApp</label>
      <input type="text" name="wa_gateway_token" class="form-control form-control-sm" placeholder="Token gateway" value="<?= e(get_setting('wa_gateway_token')) ?>">
    </div>
    <div class="col-6">
      <label class="form-label small fw-semibold">OneSignal App ID (Push HP Opsional)</label>
      <input type="text" name="onesignal_app_id" class="form-control form-control-sm" placeholder="App ID OneSignal" value="<?= e(get_setting('onesignal_app_id')) ?>">
    </div>
    <div class="col-6">
      <label class="form-label small fw-semibold">OneSignal REST API Key</label>
      <input type="text" name="onesignal_api_key" class="form-control form-control-sm" placeholder="REST API Key" value="<?= e(get_setting('onesignal_api_key')) ?>">
    </div>

    <div class="col-12 d-flex flex-wrap gap-3 mt-2">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="tampilkan_kas_publik" id="kasPublik" <?= get_setting('tampilkan_kas_publik', '1') === '1' ? 'checked' : '' ?>>
        <label class="form-check-label small" for="kasPublik">Tampilkan Summary Kas di Index Publik</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="auto_notif_enabled" id="autoNotif" <?= get_setting('auto_notif_enabled', '1') === '1' ? 'checked' : '' ?>>
        <label class="form-check-label small" for="autoNotif">Aktifkan Tagihan & Notifikasi Bulanan Otomatis</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="allow_bayar_bulanan" id="allowBulanan" <?= get_setting('allow_bayar_bulanan', '1') === '1' ? 'checked' : '' ?>>
        <label class="form-check-label small text-success fw-semibold" for="allowBulanan">Izinkan Pembayaran Iuran Bulanan (Default: Aktif)</label>
      </div>
    </div>

    <div class="col-12 mt-2">
      <button type="submit" class="btn btn-sm btn-aka px-4"><i class="bi bi-save me-1"></i> Simpan Semua Pengaturan</button>
    </div>
  </form>
</div>

<!-- Jenis Iuran (Modal CRUD) -->
<div class="aka-card p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h2 class="h6 fw-bold text-success mb-0"><i class="bi bi-tags-fill me-1"></i> Jenis & Nominal Iuran</h2>
    <button type="button" class="btn btn-sm btn-aka rounded-pill px-3" onclick="openIuranModal()">
      <i class="bi bi-plus-lg me-1"></i> Tambah
    </button>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead><tr><th>Nama Iuran</th><th>Periode</th><th class="text-end">Nominal</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($iuranList as $ji): ?>
          <tr>
            <td class="fw-semibold"><?= e($ji['nama_iuran']) ?></td>
            <td><?= e(ucfirst($ji['periode'])) ?></td>
            <td class="text-end"><?= e(rupiah($ji['nominal_default'])) ?></td>
            <td class="text-end text-nowrap">
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_iuran">
                <input type="hidden" name="id" value="<?= (int)$ji['id'] ?>">
                <button type="submit" class="btn btn-sm <?= $ji['aktif'] ? 'btn-success' : 'btn-outline-secondary' ?> py-0 px-2">
                  <?= $ji['aktif'] ? 'Aktif' : 'Off' ?>
                </button>
              </form>
              <button type="button" class="btn btn-sm btn-outline-success py-0 px-2"
                onclick='openIuranModal(<?= json_encode($ji, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus jenis iuran ini?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_iuran">
                <input type="hidden" name="id" value="<?= (int)$ji['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Referensi Wilayah RT & RW (Modal CRUD) -->
<div class="aka-card p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h2 class="h6 fw-bold text-success mb-0"><i class="bi bi-signpost-split-fill me-1"></i> Referensi Wilayah RT & RW</h2>
    <button type="button" class="btn btn-sm btn-aka rounded-pill px-3" onclick="openRtRwModal()">
      <i class="bi bi-plus-lg me-1"></i> Tambah
    </button>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead><tr><th>RT / RW</th><th>Cakupan Blok & Keterangan</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($rtrwList as $rr): ?>
          <tr>
            <td class="fw-bold text-success text-nowrap">RT <?= e($rr['rt']) ?> / RW <?= e($rr['rw']) ?></td>
            <td>
              <?php if (!empty($rr['cakupan_blok'])): ?>
                <div class="fw-semibold small"><?= e($rr['cakupan_blok']) ?></div>
              <?php endif; ?>
              <small class="text-muted"><?= e($rr['keterangan'] ?: '-') ?></small>
            </td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-success py-0 px-2"
                onclick='openRtRwModal(<?= json_encode($rr, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus referensi RT/RW ini?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_rtrw">
                <input type="hidden" name="id" value="<?= (int)$rr['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Manajemen Pengurus / Role (Modal CRUD) -->
<div class="aka-card p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h2 class="h6 fw-bold text-success mb-0"><i class="bi bi-person-gear me-1"></i> Akun Pengurus (Admin / Super Admin)</h2>
    <button type="button" class="btn btn-sm btn-aka rounded-pill px-3" onclick="openAdminModal()">
      <i class="bi bi-plus-lg me-1"></i> Tambah
    </button>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead><tr><th>Nama & Login</th><th>Role</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($adminList as $adm): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($adm['nama_lengkap']) ?></div>
              <small class="text-muted"><?= e($adm['no_hp']) ?><?= $adm['username'] ? ' / ' . e($adm['username']) : '' ?></small>
            </td>
            <td>
              <span class="badge bg-success-subtle text-success"><?= e(role_label($adm['role'])) ?></span>
              <?php if ($adm['role'] === 'admin'): ?>
                <div class="text-muted" style="font-size:0.72rem;">
                  Wilayah: <?= ($adm['rt'] || $adm['rw']) ? 'RT ' . e($adm['rt'] ?: '-') . ' / RW ' . e($adm['rw'] ?: '-') : 'Semua RT/RW' ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-success py-0 px-2"
                onclick='openAdminModal(<?= json_encode($adm, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                <i class="bi bi-pencil"></i>
              </button>
              <?php if ((int)$adm['id'] !== (int)$user['id']): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Hapus akun pengurus ini?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_admin">
                  <input type="hidden" name="id" value="<?= (int)$adm['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Struktur Pengurus RT / Paguyuban (Ditampilkan di Beranda) -->
<div class="aka-card p-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h2 class="h6 fw-bold text-success mb-0"><i class="bi bi-diagram-3-fill me-1"></i> Struktur Pengurus (Tampil di Beranda)</h2>
    <button type="button" class="btn btn-sm btn-aka rounded-pill px-3" onclick="openStrukturModal()">
      <i class="bi bi-plus-lg me-1"></i> Tambah
    </button>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead><tr><th>Urut</th><th>Nama & Jabatan</th><th>No. HP</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($strukturList as $st): ?>
          <tr>
            <td><?= (int)$st['urutan'] ?></td>
            <td>
              <div class="fw-semibold"><?= e($st['nama']) ?></div>
              <small class="text-success"><?= e($st['jabatan']) ?></small>
              <?php if (!empty($st['rt']) || !empty($st['rw'])): ?>
                <div><span class="badge bg-success-subtle text-success" style="font-size:0.65rem;">RT <?= e($st['rt'] ?: '-') ?> / RW <?= e($st['rw'] ?: '-') ?></span></div>
              <?php else: ?>
                <div><span class="badge bg-light text-muted border" style="font-size:0.65rem;">Umum / Paguyuban</span></div>
              <?php endif; ?>
            </td>
            <td><?= e($st['no_hp'] ?: '-') ?></td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-success py-0 px-2"
                onclick='openStrukturModal(<?= json_encode($st, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus pengurus ini dari struktur?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_struktur">
                <input type="hidden" name="id" value="<?= (int)$st['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Log Aktivitas -->
<div class="aka-card p-3">
  <h2 class="h6 fw-bold text-success mb-2"><i class="bi bi-activity me-1"></i> 10 Log Aktivitas Terbaru</h2>
  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Detail</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $lg): ?>
          <tr>
            <td class="text-nowrap"><?= e(tanggal_indo($lg['created_at'], true)) ?></td>
            <td><?= e($lg['nama_lengkap'] ?: 'Sistem') ?></td>
            <td class="fw-semibold"><?= e($lg['aksi']) ?></td>
            <td><small class="text-muted"><?= e($lg['detail'] ?? '-') ?></small></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Jenis Iuran -->
<div class="modal fade" id="iuranModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content rounded-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_iuran">
      <input type="hidden" name="id" id="iu_id" value="0">
      <div class="modal-header border-0 pb-1">
        <h2 class="h6 fw-bold text-success modal-title" id="iuModalTitle">Tambah Jenis Iuran</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row g-2 pt-2">
        <div class="col-12">
          <label class="form-label small fw-semibold">Nama Iuran</label>
          <input type="text" name="nama_iuran" id="iu_nama" class="form-control form-control-sm" required>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Nominal Default (Rp)</label>
          <input type="number" name="nominal_default" id="iu_nom" class="form-control form-control-sm" required>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Periode</label>
          <select name="periode" id="iu_per" class="form-select form-select-sm">
            <option value="bulanan">Bulanan</option>
            <option value="insidental">Insidental</option>
          </select>
        </div>
      </div>
      <div class="modal-footer border-0 pt-1">
        <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-sm btn-aka px-4">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Akun Pengurus -->
<div class="modal fade" id="adminModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content rounded-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_admin">
      <input type="hidden" name="id" id="ad_id" value="0">
      <div class="modal-header border-0 pb-1">
        <h2 class="h6 fw-bold text-success modal-title" id="adModalTitle">Tambah Akun Pengurus</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row g-2 pt-2">
        <div class="col-12">
          <label class="form-label small fw-semibold">Nama Lengkap</label>
          <input type="text" name="nama_lengkap" id="ad_nama" class="form-control form-control-sm" required>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">No. HP Aktif</label>
          <input type="text" name="no_hp" id="ad_hp" class="form-control form-control-sm" required>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Username (Opsional)</label>
          <input type="text" name="username" id="ad_user" class="form-control form-control-sm">
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Role</label>
          <select name="role" id="ad_role" class="form-select form-select-sm">
            <option value="admin">Admin RT/RW</option>
            <option value="super_admin">Super Admin</option>
          </select>
        </div>
        <div class="col-3">
          <label class="form-label small fw-semibold">Wilayah RT</label>
          <select name="rt" id="ad_rt" class="form-select form-select-sm">
            <option value="">Semua</option>
            <?php foreach ($rtOptions as $rtOpt): ?>
              <option value="<?= e($rtOpt) ?>">RT <?= e($rtOpt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-3">
          <label class="form-label small fw-semibold">Wilayah RW</label>
          <select name="rw" id="ad_rw" class="form-select form-select-sm">
            <option value="">Semua</option>
            <?php foreach ($rwOptions as $rwOpt): ?>
              <option value="<?= e($rwOpt) ?>">RW <?= e($rwOpt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold" id="ad_pwd_lbl">Password</label>
          <input type="password" name="password" id="ad_pwd" class="form-control form-control-sm" placeholder="Default: password123">
        </div>
      </div>
      <div class="modal-footer border-0 pt-1">
        <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-sm btn-aka px-4">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Referensi RT/RW -->
<div class="modal fade" id="rtrwModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content rounded-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_rtrw">
      <input type="hidden" name="id" id="rr_id" value="0">
      <div class="modal-header border-0 pb-1">
        <h2 class="h6 fw-bold text-success modal-title" id="rrModalTitle">Tambah Referensi RT / RW</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row g-2 pt-2">
        <div class="col-6">
          <label class="form-label small fw-semibold">Nomor RT</label>
          <input type="text" name="rt" id="rr_rt" class="form-control form-control-sm" placeholder="01" required>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Nomor RW</label>
          <input type="text" name="rw" id="rr_rw" class="form-control form-control-sm" placeholder="09" required>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold">Keterangan Wilayah</label>
          <input type="text" name="keterangan" id="rr_ket" class="form-control form-control-sm" placeholder="Contoh: Wilayah RT 01 RW 09">
          <small class="text-muted">Cakupan blok terisi otomatis dari menu <a href="<?= e(base_url('admin/wilayah.php')) ?>">Wilayah RT/RW</a>.</small>
        </div>
      </div>
      <div class="modal-footer border-0 pt-1">
        <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-sm btn-aka px-4">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Struktur Pengurus -->
<div class="modal fade" id="strukturModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" enctype="multipart/form-data" class="modal-content rounded-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_struktur">
      <input type="hidden" name="id" id="st_id" value="0">
      <div class="modal-header border-0 pb-1">
        <h2 class="h6 fw-bold text-success modal-title" id="stModalTitle">Tambah Struktur Pengurus</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row g-2 pt-2">
        <div class="col-6">
          <label class="form-label small fw-semibold">Nama Pengurus</label>
          <input type="text" name="nama" id="st_nama" class="form-control form-control-sm" required>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Jabatan</label>
          <input type="text" name="jabatan" id="st_jab" class="form-control form-control-sm" required>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Wilayah RT (Referensi)</label>
          <select name="rt" id="st_rt" class="form-select form-select-sm">
            <option value="">Umum / Paguyuban</option>
            <?php foreach ($rtOptions as $rtOpt): ?>
              <option value="<?= e($rtOpt) ?>">RT <?= e($rtOpt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Wilayah RW (Referensi)</label>
          <select name="rw" id="st_rw" class="form-select form-select-sm">
            <option value="">Umum / Paguyuban</option>
            <?php foreach ($rwOptions as $rwOpt): ?>
              <option value="<?= e($rwOpt) ?>">RW <?= e($rwOpt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">No. HP WA</label>
          <input type="text" name="no_hp" id="st_hp" class="form-control form-control-sm">
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Urutan Tampil</label>
          <input type="number" name="urutan" id="st_urut" class="form-control form-control-sm" value="1" min="1">
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold">Foto (Opsional)</label>
          <input type="file" name="foto" class="form-control form-control-sm" accept="image/*">
        </div>
      </div>
      <div class="modal-footer border-0 pt-1">
        <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-sm btn-aka px-4">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
function openStrukturModal(data = null) {
  document.getElementById('stModalTitle').textContent = data ? 'Edit Struktur Pengurus' : 'Tambah Struktur Pengurus';
  document.getElementById('st_id').value = data ? data.id : 0;
  document.getElementById('st_nama').value = data ? data.nama : '';
  document.getElementById('st_jab').value = data ? data.jabatan : '';
  document.getElementById('st_rt').value = data ? (data.rt || '') : '';
  document.getElementById('st_rw').value = data ? (data.rw || '') : '';
  document.getElementById('st_hp').value = data ? (data.no_hp || '') : '';
  document.getElementById('st_urut').value = data ? (data.urutan || 1) : 1;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('strukturModal')).show();
}
function openRtRwModal(data = null) {
  document.getElementById('rrModalTitle').textContent = data ? 'Edit Referensi RT / RW' : 'Tambah Referensi RT / RW';
  document.getElementById('rr_id').value = data ? data.id : 0;
  document.getElementById('rr_rt').value = data ? data.rt : '';
  document.getElementById('rr_rw').value = data ? data.rw : '';
  document.getElementById('rr_ket').value = data ? (data.keterangan || '') : '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('rtrwModal')).show();
}
function openIuranModal(data = null) {
  document.getElementById('iuModalTitle').textContent = data ? 'Edit Jenis Iuran' : 'Tambah Jenis Iuran';
  document.getElementById('iu_id').value = data ? data.id : 0;
  document.getElementById('iu_nama').value = data ? data.nama_iuran : '';
  document.getElementById('iu_nom').value = data ? parseInt(data.nominal_default) : '';
  document.getElementById('iu_per').value = data ? data.periode : 'bulanan';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('iuranModal')).show();
}
function openAdminModal(data = null) {
  document.getElementById('adModalTitle').textContent = data ? 'Edit Akun Pengurus' : 'Tambah Akun Pengurus';
  document.getElementById('ad_id').value = data ? data.id : 0;
  document.getElementById('ad_nama').value = data ? data.nama_lengkap : '';
  document.getElementById('ad_hp').value = data ? data.no_hp : '';
  document.getElementById('ad_user').value = data ? (data.username || '') : '';
  document.getElementById('ad_role').value = data ? data.role : 'admin';
  document.getElementById('ad_rt').value = data ? (data.rt || '') : '';
  document.getElementById('ad_rw').value = data ? (data.rw || '') : '';
  document.getElementById('ad_pwd').value = '';
  document.getElementById('ad_pwd_lbl').textContent = data ? 'Password Baru (Opsional)' : 'Password';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('adminModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
