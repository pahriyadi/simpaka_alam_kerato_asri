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
        $id           = (int)($_POST['id'] ?? 0);
        $namaBlok     = strtoupper(trim($_POST['nama_blok'] ?? ''));
        $nomorRumah   = strtoupper(trim($_POST['nomor_rumah'] ?? ''));
        $rt           = str_pad(trim($_POST['rt'] ?? ($adminRt ?: '01')), 2, '0', STR_PAD_LEFT);
        $rw           = str_pad(trim($_POST['rw'] ?? ($adminRw ?: '01')), 2, '0', STR_PAD_LEFT);
        $statusHunian = $_POST['status_hunian'] ?? 'dihuni';
        $keterangan   = trim($_POST['keterangan'] ?? '');

        if ($namaBlok === '' || $nomorRumah === '') {
            set_flash('danger', 'Nama Blok dan Nomor Rumah wajib diisi.');
        } else {
            try {
                if ($id > 0) {
                    $sqlUpd = 'UPDATE blok_rumah SET nama_blok = ?, nomor_rumah = ?, rt = ?, rw = ?, status_hunian = ?, keterangan = ? WHERE id = ?';
                    $updParams = [$namaBlok, $nomorRumah, $rt, $rw, $statusHunian, $keterangan, $id];
                    if ($adminRt !== '' && $adminRw !== '') {
                        $sqlUpd .= ' AND rt = ? AND rw = ?';
                        $updParams[] = $adminRt;
                        $updParams[] = $adminRw;
                    }
                    $pdo->prepare($sqlUpd)->execute($updParams);
                    log_activity('Update Blok Rumah', "Blok {$namaBlok}.{$nomorRumah} (RT {$rt}/RW {$rw})");
                    set_flash('success', "Data Blok {$namaBlok}.{$nomorRumah} berhasil diperbarui.");
                } else {
                    $stmt = $pdo->prepare('INSERT INTO blok_rumah (nama_blok, nomor_rumah, rt, rw, status_hunian, keterangan) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$namaBlok, $nomorRumah, $rt, $rw, $statusHunian, $keterangan]);
                    log_activity('Tambah Blok Rumah', "Blok {$namaBlok}.{$nomorRumah} (RT {$rt}/RW {$rw})");
                    set_flash('success', "Blok {$namaBlok}.{$nomorRumah} berhasil ditambahkan.");
                }
            } catch (PDOException $e) {
                set_flash('danger', 'Blok & Nomor Rumah tersebut sudah terdaftar.');
            }
        }
        redirect('admin/blok_rumah.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            if ($adminRt !== '' && $adminRw !== '') {
                $pdo->prepare('DELETE FROM blok_rumah WHERE id = ? AND rt = ? AND rw = ?')->execute([$id, $adminRt, $adminRw]);
            } else {
                $pdo->prepare('DELETE FROM blok_rumah WHERE id = ?')->execute([$id]);
            }
            log_activity('Hapus Blok Rumah', "ID Blok #{$id}");
            set_flash('success', 'Data blok rumah berhasil dihapus.');
        }
        redirect('admin/blok_rumah.php');
    }
}

$search       = trim($_GET['q'] ?? '');
$filterStatus = $_GET['status'] ?? '';
$filterRt     = $adminRt !== '' ? $adminRt : trim($_GET['rt'] ?? '');
$filterRw     = $adminRw !== '' ? $adminRw : trim($_GET['rw'] ?? '');
$perPage      = 10;
$page         = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($page - 1) * $perPage;

$where  = ' WHERE 1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (b.nama_blok LIKE ? OR b.nomor_rumah LIKE ? OR b.keterangan LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like, $like);
}
if ($filterStatus !== '') {
    $where .= ' AND b.status_hunian = ?';
    $params[] = $filterStatus;
}
if ($filterRt !== '') {
    $where .= ' AND b.rt = ?';
    $params[] = str_pad($filterRt, 2, '0', STR_PAD_LEFT);
}
if ($filterRw !== '') {
    $where .= ' AND b.rw = ?';
    $params[] = str_pad($filterRw, 2, '0', STR_PAD_LEFT);
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM blok_rumah b {$where}");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = "
    SELECT b.*,
           GROUP_CONCAT(u.nama_lengkap SEPARATOR ', ') AS nama_penghuni
    FROM blok_rumah b
    LEFT JOIN warga_profil wp ON wp.blok_rumah_id = b.id
    LEFT JOIN users u ON u.id = wp.user_id AND u.status_aktif = 1
    {$where}
    GROUP BY b.id
    ORDER BY b.nama_blok ASC, b.nomor_rumah ASC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$blokList  = $stmt->fetchAll();
$rtOptions = get_rt_list();
$rwOptions = get_rw_list();
$blokRules = $pdo->query('SELECT kode_blok, nomor_blok, rt, rw FROM referensi_blok')->fetchAll();

$pageTitle = 'Data Blok Rumah';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin_nav.php';
?>

<div class="aka-card p-3">
  <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1 class="h6 fw-bold text-success mb-0"><i class="bi bi-houses-fill me-1"></i> Data Blok & Unit Rumah</h1>
      <small class="text-muted">
        Total: <strong><?= $totalRows ?> unit</strong>
        <?= ($adminRt || $adminRw) ? ' &bull; Wilayah RT ' . e($adminRt ?: '-') . ' / RW ' . e($adminRw ?: '-') : '' ?>
      </small>
    </div>
    <button type="button" class="btn btn-sm btn-aka rounded-pill px-3" onclick="openBlokModal()">
      <i class="bi bi-plus-lg me-1"></i> Tambah
    </button>
  </div>

  <!-- Pencarian & Filter -->
  <form method="get" class="row g-2 mb-3">
    <div class="col-6 col-md-4">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Cari blok, no rumah..." value="<?= e($search) ?>">
    </div>
    <div class="col-6 col-md-3">
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Semua Status</option>
        <option value="dihuni" <?= $filterStatus === 'dihuni' ? 'selected' : '' ?>>Dihuni</option>
        <option value="kosong" <?= $filterStatus === 'kosong' ? 'selected' : '' ?>>Kosong</option>
        <option value="dikontrakkan" <?= $filterStatus === 'dikontrakkan' ? 'selected' : '' ?>>Dikontrakkan</option>
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
          <th>Blok/No</th>
          <th>RT/RW & Status</th>
          <th>Penghuni</th>
          <th class="text-end">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($blokList)): ?>
          <tr><td colspan="4" class="text-center text-muted py-3">Data tidak ditemukan.</td></tr>
        <?php endif; ?>
        <?php foreach ($blokList as $b): ?>
          <tr>
            <td class="fw-bold text-success text-nowrap">Blok <?= e($b['nama_blok']) ?>.<?= e($b['nomor_rumah']) ?></td>
            <td>
              <span class="badge bg-success-subtle text-success rounded-pill">RT <?= e($b['rt']) ?>/RW <?= e($b['rw']) ?></span>
              <span class="badge bg-light text-dark border rounded-pill"><?= e(ucfirst($b['status_hunian'])) ?></span>
              <?php if (!empty($b['keterangan'])): ?>
                <div class="text-muted" style="font-size:0.72rem;"><?= e($b['keterangan']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= e($b['nama_penghuni'] ?: '-') ?></td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-success py-0 px-2"
                onclick='openBlokModal(<?= json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus blok rumah ini?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
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

<!-- Modal Tambah / Edit Blok Rumah -->
<div class="modal fade" id="blokModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content rounded-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="blok_id" value="0">
      <div class="modal-header border-0 pb-1">
        <h2 class="h6 fw-bold text-success modal-title" id="blokModalTitle">Tambah Blok Rumah</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-2">
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label small fw-semibold">Abjad Blok</label>
            <select name="nama_blok" id="blok_nama" class="form-select form-select-sm" onchange="autoMatchRtRw()" required>
              <?php foreach (range('A', 'Z') as $h): ?>
                <option value="<?= $h ?>">Blok <?= $h ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label small fw-semibold">No. Rumah</label>
            <input type="text" name="nomor_rumah" id="blok_nomor" class="form-control form-control-sm" placeholder="17" oninput="autoMatchRtRw()" required>
          </div>
          <div class="col-6">
            <label class="form-label small fw-semibold">RT</label>
            <select name="rt" id="blok_rt" class="form-select form-select-sm" required>
              <?php foreach ($rtOptions as $rtOpt): ?>
                <option value="<?= e($rtOpt) ?>" <?= ($adminRt ?: '01') === $rtOpt ? 'selected' : '' ?>>RT <?= e($rtOpt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label small fw-semibold">RW</label>
            <select name="rw" id="blok_rw" class="form-select form-select-sm" required>
              <?php foreach ($rwOptions as $rwOpt): ?>
                <option value="<?= e($rwOpt) ?>" <?= ($adminRw ?: '01') === $rwOpt ? 'selected' : '' ?>>RW <?= e($rwOpt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold">Status Hunian</label>
            <select name="status_hunian" id="blok_status" class="form-select form-select-sm">
              <option value="dihuni">Dihuni</option>
              <option value="kosong">Kosong</option>
              <option value="dikontrakkan">Dikontrakkan</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small fw-semibold">Keterangan</label>
            <input type="text" name="keterangan" id="blok_ket" class="form-control form-control-sm" placeholder="Catatan opsional">
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
const blokRules = <?= json_encode($blokRules) ?>;
function autoMatchRtRw() {
  const b = document.getElementById('blok_nama').value;
  let n = document.getElementById('blok_nomor').value.trim().toUpperCase();
  if (!b || !n) return;
  if (/^\d$/.test(n)) n = '0' + n;
  const match = blokRules.find(r => r.kode_blok === b && r.nomor_blok.toUpperCase() === n);
  if (match) {
    document.getElementById('blok_rt').value = match.rt;
    document.getElementById('blok_rw').value = match.rw;
  }
}
function openBlokModal(data = null) {
  document.getElementById('blokModalTitle').textContent = data ? 'Edit Blok Rumah' : 'Tambah Blok Rumah';
  document.getElementById('blok_id').value = data ? data.id : 0;
  document.getElementById('blok_nama').value = data ? data.nama_blok : 'A';
  document.getElementById('blok_nomor').value = data ? data.nomor_rumah : '';
  document.getElementById('blok_rt').value = data ? (data.rt || '01') : '<?= e($adminRt ?: '01') ?>';
  document.getElementById('blok_rw').value = data ? (data.rw || '01') : '<?= e($adminRw ?: '01') ?>';
  document.getElementById('blok_status').value = data ? data.status_hunian : 'dihuni';
  document.getElementById('blok_ket').value = data ? (data.keterangan || '') : '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('blokModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
