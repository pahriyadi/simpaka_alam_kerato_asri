<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
$user = require_role(['super_admin', 'admin']);
$pdo  = db();

$adminRt = ($user['role'] === 'admin' && !empty($user['rt'])) ? $user['rt'] : '';
$adminRw = ($user['role'] === 'admin' && !empty($user['rw'])) ? $user['rw'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id             = (int)($_POST['id'] ?? 0);
        $nama           = trim($_POST['nama_lengkap'] ?? '');
        $noHp           = normalize_phone($_POST['no_hp'] ?? '');
        $blokId         = !empty($_POST['blok_rumah_id']) ? (int)$_POST['blok_rumah_id'] : null;
        $rt             = str_pad(trim($_POST['rt'] ?? ($adminRt ?: '01')), 2, '0', STR_PAD_LEFT);
        $rw             = str_pad(trim($_POST['rw'] ?? ($adminRw ?: '01')), 2, '0', STR_PAD_LEFT);

        // Auto-generate RT & RW dari Blok & Nomor Rumah yang dipilih
        if ($blokId !== null) {
            $bStmt = $pdo->prepare('
                SELECT COALESCE(rb.rt, b.rt) AS rt, COALESCE(rb.rw, b.rw) AS rw
                FROM blok_rumah b
                LEFT JOIN referensi_blok rb ON rb.kode_blok = b.nama_blok AND rb.nomor_blok = b.nomor_rumah
                WHERE b.id = ? LIMIT 1
            ');
            $bStmt->execute([$blokId]);
            if ($bRow = $bStmt->fetch()) {
                $rt = $bRow['rt'];
                $rw = $bRow['rw'];
            }
        }
        $statusKtp      = $_POST['status_ktp'] ?? 'domisili_luar';
        $asalDomisili   = trim($_POST['asal_domisili'] ?? '');
        $statusPenghuni = $_POST['status_penghuni'] ?? 'pemilik';
        $statusTinggal  = $_POST['status_tinggal'] ?? 'menetap';
        $jmlKeluarga    = max(1, (int)($_POST['jumlah_anggota_keluarga'] ?? 1));
        $tglMasuk       = !empty($_POST['tanggal_masuk']) ? $_POST['tanggal_masuk'] : null;
        $password       = $_POST['password'] ?? '';
        $statusAktif    = isset($_POST['status_aktif']) ? 1 : 0;

        if ($nama === '' || $noHp === '') {
            set_flash('danger', 'Nama lengkap dan Nomor HP aktif wajib diisi.');
            redirect('admin/warga.php');
        }

        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                $pdo->prepare('UPDATE users SET nama_lengkap = ?, no_hp = ?, rt = ?, rw = ?, status_aktif = ? WHERE id = ? AND role = "warga"')
                    ->execute([$nama, $noHp, $rt, $rw, $statusAktif, $id]);
                if ($password !== '') {
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                }
                $pdo->prepare('
                    INSERT INTO warga_profil (user_id, blok_rumah_id, rt, rw, status_ktp, asal_domisili, status_penghuni, status_tinggal, jumlah_anggota_keluarga, tanggal_masuk)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                      blok_rumah_id = VALUES(blok_rumah_id),
                      rt = VALUES(rt),
                      rw = VALUES(rw),
                      status_ktp = VALUES(status_ktp),
                      asal_domisili = VALUES(asal_domisili),
                      status_penghuni = VALUES(status_penghuni),
                      status_tinggal = VALUES(status_tinggal),
                      jumlah_anggota_keluarga = VALUES(jumlah_anggota_keluarga),
                      tanggal_masuk = VALUES(tanggal_masuk)
                ')->execute([$id, $blokId, $rt, $rw, $statusKtp, $asalDomisili, $statusPenghuni, $statusTinggal, $jmlKeluarga, $tglMasuk]);
                log_activity('Update Data Warga', "{$nama} (RT {$rt}/RW {$rw})");
                set_flash('success', "Data warga {$nama} berhasil diperbarui.");
            } else {
                $pwdHash = password_hash($password !== '' ? $password : 'password123', PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (nama_lengkap, no_hp, password_hash, role, rt, rw, status_aktif) VALUES (?, ?, ?, "warga", ?, ?, 1)');
                $stmt->execute([$nama, $noHp, $pwdHash, $rt, $rw]);
                $newId = (int)$pdo->lastInsertId();

                $pdo->prepare('
                    INSERT INTO warga_profil (user_id, blok_rumah_id, rt, rw, status_ktp, asal_domisili, status_penghuni, status_tinggal, jumlah_anggota_keluarga, tanggal_masuk)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ')->execute([$newId, $blokId, $rt, $rw, $statusKtp, $asalDomisili, $statusPenghuni, $statusTinggal, $jmlKeluarga, $tglMasuk]);
                log_activity('Tambah Warga', "{$nama} (RT {$rt}/RW {$rw})");
                set_flash('success', "Warga {$nama} berhasil ditambahkan.");
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            set_flash('danger', 'Gagal menyimpan data: Nomor HP mungkin sudah digunakan akun lain.');
        }
        redirect('admin/warga.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            if ($adminRt !== '' && $adminRw !== '') {
                $pdo->prepare('DELETE FROM users WHERE id = ? AND role = "warga" AND rt = ? AND rw = ?')->execute([$id, $adminRt, $adminRw]);
            } else {
                $pdo->prepare('DELETE FROM users WHERE id = ? AND role = "warga"')->execute([$id]);
            }
            log_activity('Hapus Warga', "User ID #{$id}");
            set_flash('success', 'Data warga berhasil dihapus.');
        }
        redirect('admin/warga.php');
    }
}

$filterKtp = $_GET['ktp'] ?? '';
$filterRt  = $adminRt !== '' ? $adminRt : trim($_GET['rt'] ?? '');
$filterRw  = $adminRw !== '' ? $adminRw : trim($_GET['rw'] ?? '');
$search    = trim($_GET['q'] ?? '');
$perPage   = 10;
$page      = max(1, (int)($_GET['page'] ?? 1));
$offset    = ($page - 1) * $perPage;

$where  = ' WHERE u.role = "warga"';
$params = [];
if ($filterKtp !== '') {
    $where .= ' AND wp.status_ktp = ?';
    $params[] = $filterKtp;
}
if ($filterRt !== '') {
    $where .= ' AND COALESCE(wp.rt, u.rt) = ?';
    $params[] = str_pad($filterRt, 2, '0', STR_PAD_LEFT);
}
if ($filterRw !== '') {
    $where .= ' AND COALESCE(wp.rw, u.rw) = ?';
    $params[] = str_pad($filterRw, 2, '0', STR_PAD_LEFT);
}
if ($search !== '') {
    $where .= ' AND (u.nama_lengkap LIKE ? OR u.no_hp LIKE ? OR b.nama_blok LIKE ? OR b.nomor_rumah LIKE ? OR wp.asal_domisili LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like, $like);
}

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM users u
    LEFT JOIN warga_profil wp ON wp.user_id = u.id
    LEFT JOIN blok_rumah b ON b.id = wp.blok_rumah_id
    {$where}
");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = "
    SELECT u.id, u.nama_lengkap, u.no_hp, u.status_aktif,
           COALESCE(wp.rt, u.rt, '01') AS rt, COALESCE(wp.rw, u.rw, '01') AS rw,
           wp.blok_rumah_id, wp.status_ktp, wp.asal_domisili, wp.status_penghuni, wp.status_tinggal, wp.jumlah_anggota_keluarga, wp.tanggal_masuk,
           b.nama_blok, b.nomor_rumah
    FROM users u
    LEFT JOIN warga_profil wp ON wp.user_id = u.id
    LEFT JOIN blok_rumah b ON b.id = wp.blok_rumah_id
    {$where}
    ORDER BY b.nama_blok ASC, b.nomor_rumah ASC, u.nama_lengkap ASC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$wargaList = $stmt->fetchAll();

$blokOptSql = '
    SELECT b.id, b.nama_blok, b.nomor_rumah,
           COALESCE(rb.rt, b.rt) AS rt,
           COALESCE(rb.rw, b.rw) AS rw
    FROM blok_rumah b
    LEFT JOIN referensi_blok rb ON rb.kode_blok = b.nama_blok AND rb.nomor_blok = b.nomor_rumah
';
$blokOptParams = [];
if ($adminRt !== '' && $adminRw !== '') {
    $blokOptSql .= ' WHERE COALESCE(rb.rt, b.rt) = ? AND COALESCE(rb.rw, b.rw) = ?';
    $blokOptParams = [$adminRt, $adminRw];
}
$blokOptSql .= ' ORDER BY b.nama_blok ASC, b.nomor_rumah ASC';
$blokOptStmt = $pdo->prepare($blokOptSql);
$blokOptStmt->execute($blokOptParams);
$blokOptions = $blokOptStmt->fetchAll();
$rtOptions   = get_rt_list();
$rwOptions   = get_rw_list();

$pageTitle = 'Data Pemilik & Warga';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin_nav.php';
?>

<div class="aka-card p-3">
  <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1 class="h6 fw-bold text-success mb-0"><i class="bi bi-people-fill me-1"></i> Data Pemilik & Warga AKA</h1>
      <small class="text-muted">
        Total: <strong><?= $totalRows ?> warga</strong>
        <?= ($adminRt || $adminRw) ? ' &bull; Wilayah RT ' . e($adminRt ?: '-') . ' / RW ' . e($adminRw ?: '-') : '' ?>
      </small>
    </div>
    <button type="button" class="btn btn-sm btn-aka rounded-pill px-3" onclick="openWargaModal()">
      <i class="bi bi-person-plus-fill me-1"></i> Tambah
    </button>
  </div>

  <form method="get" class="row g-2 mb-3">
    <div class="col-6 col-md-4">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Cari nama, HP, blok, asal..." value="<?= e($search) ?>">
    </div>
    <div class="col-6 col-md-3">
      <select name="ktp" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Semua Status KTP</option>
        <option value="penduduk_asli_perumahan" <?= $filterKtp === 'penduduk_asli_perumahan' ? 'selected' : '' ?>>KTP Asli Perumahan</option>
        <option value="domisili_luar" <?= $filterKtp === 'domisili_luar' ? 'selected' : '' ?>>Domisili Asal / Luar</option>
      </select>
    </div>
    <?php if ($adminRt === '' && $adminRw === ''): ?>
      <div class="col-4 col-md-2">
        <select name="rt" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All RT</option>
          <?php foreach ($rtOptions as $rtOpt): ?>
            <option value="<?= e($rtOpt) ?>" <?= $filterRt === $rtOpt ? 'selected' : '' ?>>RT <?= e($rtOpt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-4 col-md-2">
        <select name="rw" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All RW</option>
          <?php foreach ($rwOptions as $rwOpt): ?>
            <option value="<?= e($rwOpt) ?>" <?= $filterRw === $rwOpt ? 'selected' : '' ?>>RW <?= e($rwOpt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="<?= ($adminRt === '' && $adminRw === '') ? 'col-4 col-md-1' : 'col-12 col-md-5' ?> d-grid">
      <button type="submit" class="btn btn-sm btn-aka-outline"><i class="bi bi-search"></i></button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead>
        <tr>
          <th>Blok / RT</th>
          <th>Nama & HP</th>
          <th>Status KTP & Domisili</th>
          <th class="text-end">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($wargaList)): ?>
          <tr><td colspan="4" class="text-center text-muted py-3">Data warga tidak ditemukan.</td></tr>
        <?php endif; ?>
        <?php foreach ($wargaList as $w): ?>
          <tr>
            <td class="text-nowrap">
              <div class="fw-bold text-success"><?= $w['nama_blok'] ? e($w['nama_blok'] . '.' . $w['nomor_rumah']) : '-' ?></div>
              <small class="text-muted">RT <?= e($w['rt']) ?>/RW <?= e($w['rw']) ?></small>
            </td>
            <td>
              <div class="fw-semibold"><?= e($w['nama_lengkap']) ?></div>
              <a href="<?= e(wa_link($w['no_hp'])) ?>" target="_blank" class="small text-success text-decoration-none">
                <i class="bi bi-whatsapp"></i> <?= e($w['no_hp']) ?>
              </a>
            </td>
            <td>
              <?php if ($w['status_ktp'] === 'penduduk_asli_perumahan'): ?>
                <span class="badge bg-success-subtle text-success rounded-pill">KTP Asli Perumahan</span>
              <?php else: ?>
                <span class="badge bg-warning-subtle text-warning-emphasis rounded-pill">Domisili Asal</span>
              <?php endif; ?>
              <div class="text-muted" style="font-size:0.72rem;">
                Asal: <?= e($w['asal_domisili'] ?: '-') ?> &bull; <?= e(ucfirst($w['status_penghuni'] ?? 'pemilik')) ?> (<?= (int)($w['jumlah_anggota_keluarga'] ?? 1) ?> org)
              </div>
              <?php
                $tinggalLabel = match ($w['status_tinggal'] ?? 'menetap') {
                    'tidak_tinggal' => ['Tidak Tinggal (Kosong)', 'bg-danger-subtle text-danger'],
                    'sesekali'      => ['Sesekali / Pulang Pergi', 'bg-info-subtle text-info-emphasis'],
                    'dikontrakkan'  => ['Rumah Dikontrakkan', 'bg-secondary-subtle text-secondary'],
                    default         => ['Menetap', 'bg-success-subtle text-success'],
                };
              ?>
              <span class="badge <?= $tinggalLabel[1] ?> rounded-pill" style="font-size:0.65rem;"><?= $tinggalLabel[0] ?></span>
            </td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-success py-0 px-2"
                onclick='openWargaModal(<?= json_encode($w, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus data warga ini?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?= render_pagination($page, $totalPages) ?>
</div>

<!-- Modal Tambah / Edit Warga -->
<div class="modal fade" id="wargaModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form method="post" class="modal-content rounded-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="w_id" value="0">
      <div class="modal-header border-0 pb-1">
        <h2 class="h6 fw-bold text-success modal-title" id="wargaModalTitle">Tambah Warga Baru</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-2">
        <div class="row g-2">
          <div class="col-12 col-md-6">
            <label class="form-label small fw-semibold">Nama Pemilik / Penghuni</label>
            <input type="text" name="nama_lengkap" id="w_nama" class="form-control form-control-sm" required>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small fw-semibold">No. HP Aktif (Login & WA)</label>
            <input type="text" name="no_hp" id="w_hp" class="form-control form-control-sm" placeholder="08xxxxxxxxxx" required>
          </div>

          <div class="col-7 col-md-7">
            <label class="form-label small fw-semibold">Blok & Nomor Rumah</label>
            <select name="blok_rumah_id" id="w_blok" class="form-select form-select-sm" onchange="syncRtRwFromBlok(this)">
              <option value="">-- Pilih Blok & No. Rumah --</option>
              <?php foreach ($blokOptions as $b): ?>
                <option value="<?= (int)$b['id'] ?>" data-rt="<?= e($b['rt']) ?>" data-rw="<?= e($b['rw']) ?>">
                  Blok <?= e($b['nama_blok']) ?>.<?= e($b['nomor_rumah']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-5 col-md-5">
            <label class="form-label small fw-semibold">Wilayah RT / RW</label>
            <input type="text" id="w_rtrw_display" class="form-control form-control-sm bg-light fw-semibold text-success" readonly placeholder="Otomatis dari Blok">
            <input type="hidden" name="rt" id="w_rt" value="<?= e($adminRt ?: '01') ?>">
            <input type="hidden" name="rw" id="w_rw" value="<?= e($adminRw ?: '09') ?>">
          </div>

          <div class="col-6">
            <label class="form-label small fw-semibold">Status KTP (Tanpa NIK)</label>
            <select name="status_ktp" id="w_ktp" class="form-select form-select-sm">
              <option value="penduduk_asli_perumahan">Penduduk Asli Perumahan</option>
              <option value="domisili_luar">Masih Domisili Asal / Luar</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label small fw-semibold">Daerah Asal Domisili</label>
            <input type="text" name="asal_domisili" id="w_asal" class="form-control form-control-sm" placeholder="Contoh: Kota Mataram">
          </div>

          <div class="col-6 col-md-4">
            <label class="form-label small fw-semibold">Status Penghuni</label>
            <select name="status_penghuni" id="w_penghuni" class="form-select form-select-sm">
              <option value="pemilik">Pemilik</option>
              <option value="penyewa">Penyewa</option>
            </select>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label small fw-semibold">Status Tinggal</label>
            <select name="status_tinggal" id="w_tinggal" class="form-select form-select-sm">
              <option value="menetap">Menetap di Rumah</option>
              <option value="tidak_tinggal">Tidak Tinggal (Pemilik Tidak Ada)</option>
              <option value="sesekali">Sesekali / Pulang Pergi</option>
              <option value="dikontrakkan">Rumah Dikontrakkan</option>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small fw-semibold">Jml Org</label>
            <input type="number" name="jumlah_anggota_keluarga" id="w_jml" min="1" class="form-control form-control-sm" value="1">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small fw-semibold">Tgl Masuk</label>
            <input type="date" name="tanggal_masuk" id="w_tgl" class="form-control form-control-sm">
          </div>

          <div class="col-12">
            <label class="form-label small fw-semibold" id="w_pwd_label">Password (Default: password123)</label>
            <input type="password" name="password" id="w_pwd" class="form-control form-control-sm" placeholder="Opsional">
          </div>
          <div class="col-12" id="w_aktif_wrap" style="display:none;">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="status_aktif" id="w_aktif" checked>
              <label class="form-check-label small" for="w_aktif">Akun Aktif</label>
            </div>
          </div>
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
function syncRtRwFromBlok(sel) {
  const opt = sel.options[sel.selectedIndex];
  const disp = document.getElementById('w_rtrw_display');
  if (!opt || !opt.value) {
    disp.value = '';
    return;
  }
  const rt = opt.dataset.rt || '01';
  const rw = opt.dataset.rw || '09';
  document.getElementById('w_rt').value = rt;
  document.getElementById('w_rw').value = rw;
  disp.value = 'RT ' + rt + ' / RW ' + rw;
}
function openWargaModal(data = null) {
  document.getElementById('wargaModalTitle').textContent = data ? 'Edit Data Warga' : 'Tambah Warga Baru';
  document.getElementById('w_id').value = data ? data.id : 0;
  document.getElementById('w_nama').value = data ? data.nama_lengkap : '';
  document.getElementById('w_hp').value = data ? data.no_hp : '';
  document.getElementById('w_blok').value = data ? (data.blok_rumah_id || '') : '';
  if (data) {
    const rt = data.rt || '01';
    const rw = data.rw || '09';
    document.getElementById('w_rt').value = rt;
    document.getElementById('w_rw').value = rw;
    document.getElementById('w_rtrw_display').value = 'RT ' + rt + ' / RW ' + rw;
  } else {
    document.getElementById('w_rt').value = '<?= e($adminRt ?: '01') ?>';
    document.getElementById('w_rw').value = '<?= e($adminRw ?: '09') ?>';
    document.getElementById('w_rtrw_display').value = '';
  }
  document.getElementById('w_ktp').value = data ? (data.status_ktp || 'domisili_luar') : 'domisili_luar';
  document.getElementById('w_asal').value = data ? (data.asal_domisili || '') : '';
  document.getElementById('w_penghuni').value = data ? (data.status_penghuni || 'pemilik') : 'pemilik';
  document.getElementById('w_tinggal').value = data ? (data.status_tinggal || 'menetap') : 'menetap';
  document.getElementById('w_jml').value = data ? (data.jumlah_anggota_keluarga || 1) : 1;
  document.getElementById('w_tgl').value = data ? (data.tanggal_masuk || '') : '';
  document.getElementById('w_pwd').value = '';
  document.getElementById('w_pwd_label').textContent = data ? 'Password Baru (Kosongkan jika tetap)' : 'Password (Default: password123)';
  document.getElementById('w_aktif_wrap').style.display = data ? 'block' : 'none';
  document.getElementById('w_aktif').checked = data ? (parseInt(data.status_aktif) === 1) : true;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('wargaModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
