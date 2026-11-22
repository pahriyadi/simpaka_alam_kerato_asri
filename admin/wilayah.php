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

    if ($action === 'save_rule') {
        $id        = (int)($_POST['id'] ?? 0);
        $kodeBlok  = strtoupper(trim($_POST['kode_blok'] ?? 'A'));
        $nomorBlok = strtoupper(trim($_POST['nomor_blok'] ?? ''));
        if (ctype_digit($nomorBlok) && strlen($nomorBlok) === 1) {
            $nomorBlok = str_pad($nomorBlok, 2, '0', STR_PAD_LEFT);
        }
        $rt        = $adminRt !== '' ? $adminRt : str_pad(trim($_POST['rt'] ?? '01'), 2, '0', STR_PAD_LEFT);
        $rw        = $adminRw !== '' ? $adminRw : str_pad(trim($_POST['rw'] ?? '09'), 2, '0', STR_PAD_LEFT);
        $syncUnit  = isset($_POST['sync_unit']);

        if ($nomorBlok === '') {
            set_flash('danger', 'Nomor Blok wajib diisi.');
            redirect('admin/wilayah.php');
        }

        try {
            if ($id > 0) {
                $old = $pdo->prepare('SELECT rt, rw FROM referensi_blok WHERE id = ?');
                $old->execute([$id]);
                $oldRow = $old->fetch();

                $pdo->prepare('UPDATE referensi_blok SET kode_blok = ?, nomor_blok = ?, rt = ?, rw = ? WHERE id = ?')
                    ->execute([$kodeBlok, $nomorBlok, $rt, $rw, $id]);
                if ($oldRow) {
                    sync_cakupan_blok_rtrw($oldRow['rt'], $oldRow['rw']);
                }
                set_flash('success', "Blok {$kodeBlok}.{$nomorBlok} untuk RT {$rt}/RW {$rw} diperbarui.");
            } else {
                $pdo->prepare('INSERT INTO referensi_blok (kode_blok, nomor_blok, rt, rw) VALUES (?, ?, ?, ?)')
                    ->execute([$kodeBlok, $nomorBlok, $rt, $rw]);
                set_flash('success', "Blok {$kodeBlok}.{$nomorBlok} untuk RT {$rt}/RW {$rw} ditambahkan.");
            }

            // Pastikan referensi_rt_rw ada & sinkronkan cakupan_blok otomatis
            $pdo->prepare('INSERT IGNORE INTO referensi_rt_rw (rt, rw, keterangan, aktif) VALUES (?, ?, ?, 1)')
                ->execute([$rt, $rw, "Wilayah RT {$rt} RW {$rw}"]);
            sync_cakupan_blok_rtrw($rt, $rw);

            // Sinkronkan RT/RW di blok_rumah
            $pdo->prepare('UPDATE blok_rumah SET rt = ?, rw = ? WHERE nama_blok = ? AND nomor_rumah = ?')
                ->execute([$rt, $rw, $kodeBlok, $nomorBlok]);
            if ($syncUnit) {
                $pdo->prepare('INSERT IGNORE INTO blok_rumah (nama_blok, nomor_rumah, rt, rw, status_hunian) VALUES (?, ?, ?, ?, "dihuni")')
                    ->execute([$kodeBlok, $nomorBlok, $rt, $rw]);
            }

            log_activity('Atur Cakupan Blok', "Blok {$kodeBlok}.{$nomorBlok} -> RT {$rt}/RW {$rw}");
        } catch (PDOException $e) {
            set_flash('danger', "Blok {$kodeBlok}.{$nomorBlok} sudah terdaftar pada referensi wilayah.");
        }
        redirect('admin/wilayah.php');
    }

    if ($action === 'delete_rule') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT rt, rw FROM referensi_blok WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row && ($adminRt === '' || ($row['rt'] === $adminRt && $row['rw'] === $adminRw))) {
                $pdo->prepare('DELETE FROM referensi_blok WHERE id = ?')->execute([$id]);
                sync_cakupan_blok_rtrw($row['rt'], $row['rw']);
                set_flash('success', 'Blok dihapus dari cakupan dan keterangan wilayah diperbarui otomatis.');
            }
        }
        redirect('admin/wilayah.php');
    }
}

$search     = trim($_GET['q'] ?? '');
$filterBlok = strtoupper(trim($_GET['blok'] ?? ''));
$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($page - 1) * $perPage;

$whereRule  = ' WHERE 1=1';
$ruleParams = [];
if ($adminRt !== '') {
    $whereRule .= ' AND rt = ?';
    $ruleParams[] = $adminRt;
}
if ($adminRw !== '') {
    $whereRule .= ' AND rw = ?';
    $ruleParams[] = $adminRw;
}
if ($filterBlok !== '') {
    $whereRule .= ' AND kode_blok = ?';
    $ruleParams[] = $filterBlok;
}
if ($search !== '') {
    $whereRule .= ' AND (kode_blok LIKE ? OR nomor_blok LIKE ?)';
    $like = "%{$search}%";
    array_push($ruleParams, $like, $like);
}

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM referensi_blok {$whereRule}");
$cntStmt->execute($ruleParams);
$totalRows  = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmtRules = $pdo->prepare("SELECT * FROM referensi_blok {$whereRule} ORDER BY rw ASC, rt ASC, kode_blok ASC, nomor_blok ASC LIMIT {$perPage} OFFSET {$offset}");
$stmtRules->execute($ruleParams);
$rules = $stmtRules->fetchAll();

// Ringkasan Otomatis per RT/RW
$whereWil  = ' WHERE r.aktif = 1';
$wilParams = [];
if ($adminRt !== '') {
    $whereWil .= ' AND r.rt = ?';
    $wilParams[] = $adminRt;
}
if ($adminRw !== '') {
    $whereWil .= ' AND r.rw = ?';
    $wilParams[] = $adminRw;
}
$stmtWil = $pdo->prepare("
    SELECT r.*,
      (SELECT COUNT(*) FROM blok_rumah b WHERE b.rt = r.rt AND b.rw = r.rw) AS jml_rumah,
      (SELECT COUNT(*) FROM warga_profil wp JOIN users u ON u.id = wp.user_id WHERE u.status_aktif = 1 AND wp.rt = r.rt AND wp.rw = r.rw) AS jml_kk
    FROM referensi_rt_rw r
    {$whereWil}
    ORDER BY r.rw ASC, r.rt ASC
");
$stmtWil->execute($wilParams);
$wilayahList = $stmtWil->fetchAll();

$rtOptions = get_rt_list();
$rwOptions = get_rw_list();

$pageTitle = 'Cakupan Blok & Wilayah RT/RW';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin_nav.php';
?>

<!-- Ringkasan Wilayah Otomatis -->
<div class="aka-card p-3">
  <h1 class="h6 fw-bold text-success mb-2"><i class="bi bi-map-fill me-1"></i> Keterangan Wilayah RT / RW (Otomatis)</h1>
  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead>
        <tr>
          <th>Wilayah</th>
          <th>Cakupan Blok (Otomatis)</th>
          <th class="text-end">Rumah / KK</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($wilayahList as $w): ?>
          <tr>
            <td class="fw-bold text-success text-nowrap">RT <?= e($w['rt']) ?> / RW <?= e($w['rw']) ?></td>
            <td>
              <div class="fw-semibold small"><?= e($w['cakupan_blok'] ?: 'Belum ada blok terdaftar') ?></div>
              <small class="text-muted"><?= e($w['keterangan'] ?: '') ?></small>
            </td>
            <td class="text-end text-nowrap">
              <span class="badge bg-success-subtle text-success"><?= (int)$w['jml_rumah'] ?> Unit</span>
              <span class="badge bg-light text-dark border"><?= (int)$w['jml_kk'] ?> KK</span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Pengaturan Cakupan Blok Satu per Satu -->
<div class="aka-card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h2 class="h6 fw-bold text-success mb-0"><i class="bi bi-sliders me-1"></i> Cakupan Blok per RT/RW (<?= $totalRows ?>)</h2>
      <small class="text-muted">Masukkan satu per satu: Abjad Blok, Nomor Blok, & RT/RW</small>
    </div>
    <button type="button" class="btn btn-sm btn-aka rounded-pill px-3" onclick="openRuleModal()">
      <i class="bi bi-plus-lg me-1"></i> Tambah
    </button>
  </div>

  <form method="get" class="row g-2 mb-3">
    <div class="col-6">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Cari nomor blok..." value="<?= e($search) ?>">
    </div>
    <div class="col-4">
      <select name="blok" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">All Abjad</option>
        <?php foreach (range('A', 'Z') as $h): ?>
          <option value="<?= $h ?>" <?= $filterBlok === $h ? 'selected' : '' ?>>Blok <?= $h ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-2 d-grid">
      <button type="submit" class="btn btn-sm btn-aka-outline"><i class="bi bi-search"></i></button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead>
        <tr>
          <th>Abjad & Nomor Blok</th>
          <th>Wilayah RT / RW</th>
          <th class="text-end">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rules)): ?>
          <tr><td colspan="3" class="text-center text-muted py-3">Belum ada data cakupan blok.</td></tr>
        <?php endif; ?>
        <?php foreach ($rules as $r): ?>
          <tr>
            <td class="fw-bold text-success">Blok <?= e($r['kode_blok']) ?>.<?= e($r['nomor_blok']) ?></td>
            <td><span class="badge bg-success-subtle text-success rounded-pill">RT <?= e($r['rt']) ?> / RW <?= e($r['rw']) ?></span></td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-success py-0 px-2"
                onclick='openRuleModal(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus blok ini dari cakupan wilayah?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_rule">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
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

<!-- Modal Tambah / Edit Cakupan Blok (Satu per Satu) -->
<div class="modal fade" id="ruleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content rounded-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_rule">
      <input type="hidden" name="id" id="rl_id" value="0">
      <div class="modal-header border-0 pb-1">
        <h2 class="h6 fw-bold text-success modal-title" id="rlModalTitle">Tambah Cakupan Blok</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row g-2 pt-2">
        <div class="col-6">
          <label class="form-label small fw-semibold">Abjad Blok</label>
          <select name="kode_blok" id="rl_blok" class="form-select form-select-sm" required>
            <?php foreach (range('A', 'Z') as $huruf): ?>
              <option value="<?= $huruf ?>">Blok <?= $huruf ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Nomor Blok</label>
          <input type="text" name="nomor_blok" id="rl_nomor" class="form-control form-control-sm" placeholder="Contoh: 17 atau 01" required>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">RT (Referensi)</label>
          <select name="rt" id="rl_rt" class="form-select form-select-sm" <?= $adminRt !== '' ? 'disabled' : '' ?> required>
            <?php foreach ($rtOptions as $rtOpt): ?>
              <option value="<?= e($rtOpt) ?>" <?= ($adminRt ?: '01') === $rtOpt ? 'selected' : '' ?>>RT <?= e($rtOpt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">RW (Referensi)</label>
          <select name="rw" id="rl_rw" class="form-select form-select-sm" <?= $adminRw !== '' ? 'disabled' : '' ?> required>
            <?php foreach ($rwOptions as $rwOpt): ?>
              <option value="<?= e($rwOpt) ?>" <?= ($adminRw ?: '09') === $rwOpt ? 'selected' : '' ?>>RW <?= e($rwOpt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 mt-2">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="sync_unit" id="rl_gen" checked>
            <label class="form-check-label small text-success fw-semibold" for="rl_gen">
              Sekaligus daftarkan/perbarui di data Blok Rumah
            </label>
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
function openRuleModal(data = null) {
  document.getElementById('rlModalTitle').textContent = data ? 'Edit Cakupan Blok' : 'Tambah Cakupan Blok';
  document.getElementById('rl_id').value = data ? data.id : 0;
  document.getElementById('rl_blok').value = data ? data.kode_blok : 'D';
  document.getElementById('rl_nomor').value = data ? data.nomor_blok : '';
  if (data) {
    document.getElementById('rl_rt').value = data.rt;
    document.getElementById('rl_rw').value = data.rw;
  }
  document.getElementById('rl_gen').checked = true;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('ruleModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
