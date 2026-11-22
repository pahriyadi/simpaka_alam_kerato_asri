<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../helpers/upload.php';
require_once __DIR__ . '/../helpers/notification.php';

$user = require_role(['super_admin', 'admin']);
$pdo  = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $tanggal    = $_POST['tanggal'] ?? date('Y-m-d');
        $jenis      = $_POST['jenis'] ?? 'pengeluaran';
        $kategori   = trim($_POST['kategori'] ?? '');
        $deskripsi  = trim($_POST['deskripsi'] ?? '');
        $nominal    = (float)($_POST['nominal'] ?? 0);
        $kirimNotif = isset($_POST['kirim_notif']);

        if ($kategori === '' || $deskripsi === '' || $nominal <= 0) {
            set_flash('danger', 'Kategori, deskripsi, dan nominal wajib diisi.');
            redirect('admin/keuangan.php');
        }

        try {
            $buktiPath = handle_image_upload($_FILES['bukti_nota'] ?? [], 'nota');
            if ($id > 0) {
                if ($buktiPath) {
                    $pdo->prepare('UPDATE keuangan_kas SET tanggal = ?, jenis = ?, kategori = ?, deskripsi = ?, nominal = ?, bukti_nota = ? WHERE id = ?')
                        ->execute([$tanggal, $jenis, $kategori, $deskripsi, $nominal, $buktiPath, $id]);
                } else {
                    $pdo->prepare('UPDATE keuangan_kas SET tanggal = ?, jenis = ?, kategori = ?, deskripsi = ?, nominal = ? WHERE id = ?')
                        ->execute([$tanggal, $jenis, $kategori, $deskripsi, $nominal, $id]);
                }
                log_activity('Update Mutasi Kas', "ID #{$id} - {$kategori}");
                set_flash('success', 'Data mutasi kas berhasil diperbarui.');
            } else {
                $pdo->prepare('INSERT INTO keuangan_kas (tanggal, jenis, kategori, deskripsi, nominal, bukti_nota, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$tanggal, $jenis, $kategori, $deskripsi, $nominal, $buktiPath, $user['id']]);

                if ($jenis === 'pengeluaran' && $kirimNotif) {
                    $judul = "Pengeluaran Bendahara: {$kategori}";
                    $pesan = "Telah dicatat pengeluaran kas sebesar " . rupiah($nominal) . " pada " . tanggal_indo($tanggal) . " untuk: {$deskripsi}.";
                    broadcast_notification($judul, $pesan, 'pengeluaran_bendahara', true);
                }
                log_activity('Catat Kas ' . ucfirst($jenis), "{$kategori}: " . rupiah($nominal));
                set_flash('success', 'Catatan keuangan berhasil disimpan' . ($jenis === 'pengeluaran' && $kirimNotif ? ' dan notifikasi telah dikirim ke semua warga.' : '.'));
            }
        } catch (Throwable $e) {
            set_flash('danger', $e->getMessage());
        }
        redirect('admin/keuangan.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM keuangan_kas WHERE id = ?')->execute([$id]);
            log_activity('Hapus Mutasi Kas', "ID #{$id}");
            set_flash('success', 'Data mutasi kas berhasil dihapus.');
        }
        redirect('admin/keuangan.php');
    }
}

$search      = trim($_GET['q'] ?? '');
$filterJenis = $_GET['jenis'] ?? '';
$perPage     = 10;
$page        = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($page - 1) * $perPage;

$where  = ' WHERE 1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (kategori LIKE ? OR deskripsi LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like);
}
if ($filterJenis !== '') {
    $where .= ' AND jenis = ?';
    $params[] = $filterJenis;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM keuangan_kas {$where}");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = $pdo->prepare("SELECT * FROM keuangan_kas {$where} ORDER BY tanggal DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$kasList = $stmt->fetchAll();

$pageTitle = 'Kas & Pengeluaran Bendahara';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin_nav.php';
?>

<div class="aka-card p-3">
  <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1 class="h6 fw-bold text-success mb-0"><i class="bi bi-cash-coin me-1"></i> Mutasi Kas & Pengeluaran</h1>
      <small class="text-muted">Total: <?= $totalRows ?> transaksi</small>
    </div>
    <button type="button" class="btn btn-sm btn-aka rounded-pill px-3" onclick="openKasModal()">
      <i class="bi bi-plus-lg me-1"></i> Catat Kas
    </button>
  </div>

  <form method="get" class="row g-2 mb-3">
    <div class="col-6 col-md-6">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Cari kategori, deskripsi..." value="<?= e($search) ?>">
    </div>
    <div class="col-4 col-md-4">
      <select name="jenis" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Semua Jenis</option>
        <option value="pemasukan" <?= $filterJenis === 'pemasukan' ? 'selected' : '' ?>>Pemasukan</option>
        <option value="pengeluaran" <?= $filterJenis === 'pengeluaran' ? 'selected' : '' ?>>Pengeluaran</option>
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
          <th>Tanggal</th>
          <th>Kategori & Keterangan</th>
          <th class="text-end">Nominal</th>
          <th class="text-end">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($kasList)): ?>
          <tr><td colspan="4" class="text-center text-muted py-3">Belum ada mutasi kas.</td></tr>
        <?php endif; ?>
        <?php foreach ($kasList as $k): ?>
          <tr>
            <td class="text-nowrap"><?= e(tanggal_indo($k['tanggal'])) ?></td>
            <td>
              <div class="fw-semibold"><?= e($k['kategori']) ?></div>
              <small class="text-muted"><?= e($k['deskripsi']) ?></small>
              <?php if (!empty($k['bukti_nota'])): ?>
                <div><a href="<?= e(base_url($k['bukti_nota'])) ?>" target="_blank" class="small text-success">Lihat Nota</a></div>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap fw-bold <?= $k['jenis'] === 'pemasukan' ? 'text-success' : 'text-danger' ?>">
              <?= $k['jenis'] === 'pemasukan' ? '+' : '-' ?> <?= e(rupiah($k['nominal'])) ?>
            </td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-success py-0 px-2"
                onclick='openKasModal(<?= json_encode($k, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus mutasi kas ini?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
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

<!-- Modal Tambah / Edit Kas -->
<div class="modal fade" id="kasModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" enctype="multipart/form-data" class="modal-content rounded-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="k_id" value="0">
      <div class="modal-header border-0 pb-1">
        <h2 class="h6 fw-bold text-success modal-title" id="kasModalTitle">Catat Pemasukan / Pengeluaran</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row g-2 pt-2">
        <div class="col-6">
          <label class="form-label small fw-semibold">Tanggal</label>
          <input type="date" name="tanggal" id="k_tgl" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Jenis</label>
          <select name="jenis" id="k_jenis" class="form-select form-select-sm">
            <option value="pengeluaran">Pengeluaran Bendahara</option>
            <option value="pemasukan">Pemasukan Lainnya</option>
          </select>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Kategori</label>
          <input type="text" name="kategori" id="k_kat" class="form-control form-control-sm" placeholder="Lampu PJU / Kebersihan" required>
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Nominal (Rp)</label>
          <input type="number" name="nominal" id="k_nom" min="1" class="form-control form-control-sm" required>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold">Deskripsi / Keperluan</label>
          <textarea name="deskripsi" id="k_desk" rows="2" class="form-control form-control-sm" required></textarea>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold">Foto Nota / Bukti (Opsional)</label>
          <input type="file" name="bukti_nota" class="form-control form-control-sm" accept="image/*">
        </div>
        <div class="col-12" id="k_notif_wrap">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="kirim_notif" id="kirimNotif" checked>
            <label class="form-check-label small text-success fw-semibold" for="kirimNotif">
              Kirim Notifikasi Pengeluaran Bendahara ke seluruh warga
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
function openKasModal(data = null) {
  document.getElementById('kasModalTitle').textContent = data ? 'Edit Mutasi Kas' : 'Catat Pemasukan / Pengeluaran';
  document.getElementById('k_id').value = data ? data.id : 0;
  document.getElementById('k_tgl').value = data ? data.tanggal : '<?= date('Y-m-d') ?>';
  document.getElementById('k_jenis').value = data ? data.jenis : 'pengeluaran';
  document.getElementById('k_kat').value = data ? data.kategori : '';
  document.getElementById('k_nom').value = data ? parseInt(data.nominal) : '';
  document.getElementById('k_desk').value = data ? data.deskripsi : '';
  document.getElementById('k_notif_wrap').style.display = data ? 'none' : 'block';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('kasModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
