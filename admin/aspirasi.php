<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../helpers/notification.php';

$user = require_role(['super_admin', 'admin']);
$pdo  = db();

$adminRt = ($user['role'] === 'admin' && !empty($user['rt'])) ? $user['rt'] : '';
$adminRw = ($user['role'] === 'admin' && !empty($user['rw'])) ? $user['rw'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'update';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        $pdo->prepare('DELETE FROM aspirasi WHERE id = ?')->execute([$id]);
        set_flash('success', 'Aspirasi berhasil dihapus.');
        redirect('admin/aspirasi.php');
    }

    $status    = $_POST['status'] ?? 'diproses';
    $tanggapan = trim($_POST['tanggapan'] ?? '');

    if ($id > 0 && in_array($status, ['baru', 'diproses', 'selesai'], true)) {
        $stmt = $pdo->prepare('SELECT user_id, judul FROM aspirasi WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare('UPDATE aspirasi SET status = ?, tanggapan = ? WHERE id = ?')->execute([$status, $tanggapan, $id]);
            create_notification((int)$row['user_id'], 'Tanggapan Aspirasi Warga', "Aspirasi \"{$row['judul']}\" berstatus " . strtoupper($status) . ($tanggapan ? ": {$tanggapan}" : ''), 'pengumuman', false);
            set_flash('success', 'Tanggapan aspirasi berhasil disimpan.');
        }
    }
    redirect('admin/aspirasi.php');
}

$search       = trim($_GET['q'] ?? '');
$filterStatus = $_GET['status'] ?? '';
$perPage      = 10;
$page         = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($page - 1) * $perPage;

$where  = ' WHERE 1=1';
$params = [];
if ($adminRt !== '' && $adminRw !== '') {
    $where .= ' AND COALESCE(wp.rt, u.rt) = ? AND COALESCE(wp.rw, u.rw) = ?';
    $params[] = $adminRt;
    $params[] = $adminRw;
}
if ($filterStatus !== '') {
    $where .= ' AND a.status = ?';
    $params[] = $filterStatus;
}
if ($search !== '') {
    $where .= ' AND (a.judul LIKE ? OR a.isi LIKE ? OR u.nama_lengkap LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like, $like);
}

$cnt = $pdo->prepare("SELECT COUNT(*) FROM aspirasi a JOIN users u ON u.id = a.user_id LEFT JOIN warga_profil wp ON wp.user_id = u.id {$where}");
$cnt->execute($params);
$totalRows  = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$stmt = $pdo->prepare("
    SELECT a.*, u.nama_lengkap, b.nama_blok, b.nomor_rumah
    FROM aspirasi a
    JOIN users u ON u.id = a.user_id
    LEFT JOIN warga_profil wp ON wp.user_id = u.id
    LEFT JOIN blok_rumah b ON b.id = wp.blok_rumah_id
    {$where}
    ORDER BY FIELD(a.status, 'baru', 'diproses', 'selesai'), a.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$list = $stmt->fetchAll();

$pageTitle = 'Aspirasi & Aduan Warga';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin_nav.php';
?>

<div class="aka-card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h6 fw-bold text-success mb-0"><i class="bi bi-chat-square-text-fill me-1"></i> Aspirasi & Aduan Warga</h1>
      <small class="text-muted">Total: <?= $totalRows ?> laporan</small>
    </div>
  </div>

  <form method="get" class="row g-2 mb-3">
    <div class="col-6">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Cari judul, warga..." value="<?= e($search) ?>">
    </div>
    <div class="col-4">
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Semua Status</option>
        <option value="baru" <?= $filterStatus === 'baru' ? 'selected' : '' ?>>Baru</option>
        <option value="diproses" <?= $filterStatus === 'diproses' ? 'selected' : '' ?>>Diproses</option>
        <option value="selesai" <?= $filterStatus === 'selesai' ? 'selected' : '' ?>>Selesai</option>
      </select>
    </div>
    <div class="col-2 d-grid">
      <button type="submit" class="btn btn-sm btn-aka-outline"><i class="bi bi-search"></i></button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead><tr><th>Warga & Judul</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php if (empty($list)): ?>
          <tr><td colspan="3" class="text-center text-muted py-3">Belum ada aspirasi warga.</td></tr>
        <?php endif; ?>
        <?php foreach ($list as $a): ?>
          <tr>
            <td>
              <div class="fw-bold"><?= e($a['judul']) ?></div>
              <small class="text-muted">
                <?= e($a['nama_lengkap']) ?> <?= $a['nama_blok'] ? '(' . e($a['nama_blok'] . '.' . $a['nomor_rumah']) . ')' : '' ?>
                &bull; <?= e(ucfirst($a['kategori'])) ?>
              </small>
            </td>
            <td><span class="badge bg-success-subtle text-success rounded-pill"><?= e(strtoupper($a['status'])) ?></span></td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-success py-0 px-2"
                onclick='openAspirasiModal(<?= json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                <i class="bi bi-reply-fill"></i>
              </button>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus aspirasi ini?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
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

<!-- Modal Tanggapi Aspirasi -->
<div class="modal fade" id="aspirasiModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content rounded-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="as_id" value="0">
      <div class="modal-header border-0 pb-1">
        <h2 class="h6 fw-bold text-success modal-title">Tanggapi Aspirasi Warga</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-2">
        <div class="aka-card-soft p-2 mb-2 small">
          <div class="fw-bold" id="as_judul"></div>
          <div class="text-muted" id="as_isi"></div>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold">Status</label>
          <select name="status" id="as_status" class="form-select form-select-sm">
            <option value="baru">Baru</option>
            <option value="diproses">Diproses</option>
            <option value="selesai">Selesai</option>
          </select>
        </div>
        <div>
          <label class="form-label small fw-semibold">Tanggapan Pengurus</label>
          <textarea name="tanggapan" id="as_tanggapan" rows="3" class="form-control form-control-sm"></textarea>
        </div>
      </div>
      <div class="modal-footer border-0 pt-1">
        <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-sm btn-aka px-4">Simpan Tanggapan</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAspirasiModal(data) {
  document.getElementById('as_id').value = data.id;
  document.getElementById('as_judul').textContent = data.judul;
  document.getElementById('as_isi').textContent = data.isi;
  document.getElementById('as_status').value = data.status;
  document.getElementById('as_tanggapan').value = data.tanggapan || '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('aspirasiModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
