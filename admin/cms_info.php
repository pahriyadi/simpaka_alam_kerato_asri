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

    if ($action === 'save_pengumuman') {
        $id         = (int)($_POST['id'] ?? 0);
        $judul      = trim($_POST['judul'] ?? '');
        $isi        = trim($_POST['isi'] ?? '');
        $kategori   = $_POST['kategori'] ?? 'pengumuman';
        $pinned     = isset($_POST['is_pinned']) ? 1 : 0;
        $kirimNotif = isset($_POST['kirim_notif']);

        if ($judul !== '' && $isi !== '') {
            if ($id > 0) {
                $pdo->prepare('UPDATE pengumuman SET judul = ?, isi = ?, kategori = ?, is_pinned = ? WHERE id = ?')
                    ->execute([$judul, $isi, $kategori, $pinned, $id]);
                log_activity('Edit Pengumuman', $judul);
                set_flash('success', 'Pengumuman berhasil diperbarui.');
            } else {
                $pdo->prepare('INSERT INTO pengumuman (judul, isi, kategori, is_pinned, created_by) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$judul, $isi, $kategori, $pinned, $user['id']]);
                if ($kirimNotif) {
                    broadcast_notification("Info AKA: {$judul}", $isi, 'pengumuman', true);
                }
                log_activity('Tambah Pengumuman', $judul);
                set_flash('success', 'Pengumuman berhasil dipublikasikan di halaman Index.');
            }
        }
        redirect('admin/cms_info.php');
    }

    if ($action === 'delete_pengumuman') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM pengumuman WHERE id = ?')->execute([$id]);
            set_flash('success', 'Pengumuman dihapus.');
        }
        redirect('admin/cms_info.php');
    }

    if ($action === 'save_struktur') {
        $id      = (int)($_POST['id'] ?? 0);
        $nama    = trim($_POST['nama'] ?? '');
        $jabatan = trim($_POST['jabatan'] ?? '');
        $noHp    = normalize_phone($_POST['no_hp'] ?? '');
        $urutan  = (int)($_POST['urutan'] ?? 1);

        if ($nama !== '' && $jabatan !== '') {
            try {
                $foto = handle_image_upload($_FILES['foto'] ?? [], 'pengurus');
                if ($id > 0) {
                    if ($foto) {
                        $pdo->prepare('UPDATE struktur_organisasi SET nama = ?, jabatan = ?, no_hp = ?, foto = ?, urutan = ? WHERE id = ?')
                            ->execute([$nama, $jabatan, $noHp, $foto, $urutan, $id]);
                    } else {
                        $pdo->prepare('UPDATE struktur_organisasi SET nama = ?, jabatan = ?, no_hp = ?, urutan = ? WHERE id = ?')
                            ->execute([$nama, $jabatan, $noHp, $urutan, $id]);
                    }
                    log_activity('Edit Pengurus', "{$nama} - {$jabatan}");
                    set_flash('success', 'Data pengurus diperbarui.');
                } else {
                    $pdo->prepare('INSERT INTO struktur_organisasi (nama, jabatan, no_hp, foto, urutan) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$nama, $jabatan, $noHp, $foto, $urutan]);
                    log_activity('Tambah Pengurus', "{$nama} - {$jabatan}");
                    set_flash('success', 'Pengurus berhasil ditambahkan ke Struktur Organisasi.');
                }
            } catch (Throwable $e) {
                set_flash('danger', $e->getMessage());
            }
        }
        redirect('admin/cms_info.php');
    }

    if ($action === 'delete_struktur') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM struktur_organisasi WHERE id = ?')->execute([$id]);
            set_flash('success', 'Pengurus dihapus.');
        }
        redirect('admin/cms_info.php');
    }

    if ($action === 'save_lokasi') {
        set_setting('sambutan_index', trim($_POST['sambutan_index'] ?? ''));
        set_setting('alamat_perumahan', trim($_POST['alamat_perumahan'] ?? ''));
        set_setting('maps_embed_url', trim($_POST['maps_embed_url'] ?? ''));
        set_setting('maps_link_url', trim($_POST['maps_link_url'] ?? ''));
        log_activity('Update Info Lokasi Index');
        set_flash('success', 'Informasi sambutan & lokasi perumahan di Index berhasil diperbarui.');
        redirect('admin/cms_info.php');
    }
}

$searchP = trim($_GET['qp'] ?? '');
$perPage = 6;
$pageP   = max(1, (int)($_GET['page'] ?? 1));
$offsetP = ($pageP - 1) * $perPage;

$whereP  = ' WHERE 1=1';
$paramsP = [];
if ($searchP !== '') {
    $whereP .= ' AND (judul LIKE ? OR isi LIKE ?)';
    $paramsP = ["%{$searchP}%", "%{$searchP}%"];
}
$cntP = $pdo->prepare("SELECT COUNT(*) FROM pengumuman {$whereP}");
$cntP->execute($paramsP);
$totalP = (int)$cntP->fetchColumn();
$totalPagesP = max(1, (int)ceil($totalP / $perPage));

$stmtP = $pdo->prepare("SELECT * FROM pengumuman {$whereP} ORDER BY is_pinned DESC, created_at DESC LIMIT {$perPage} OFFSET {$offsetP}");
$stmtP->execute($paramsP);
$pengumumanList = $stmtP->fetchAll();

$strukturList = $pdo->query('SELECT * FROM struktur_organisasi ORDER BY urutan ASC, id ASC')->fetchAll();

$pageTitle = 'Atur Index & Informasi Umum';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin_nav.php';
?>

<!-- Pengumuman -->
<div class="aka-card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h6 fw-bold text-success mb-0"><i class="bi bi-megaphone-fill me-1"></i> Pengumuman Index (<?= $totalP ?>)</h1>
    <button type="button" class="btn btn-sm btn-aka rounded-pill px-3" onclick="openPengumumanModal()">
      <i class="bi bi-plus-lg me-1"></i> Tambah
    </button>
  </div>

  <form method="get" class="row g-2 mb-3">
    <div class="col-9">
      <input type="text" name="qp" class="form-control form-control-sm" placeholder="Cari pengumuman..." value="<?= e($searchP) ?>">
    </div>
    <div class="col-3 d-grid">
      <button type="submit" class="btn btn-sm btn-aka-outline">Cari</button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead><tr><th>Judul & Kategori</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($pengumumanList as $p): ?>
          <tr>
            <td>
              <div class="fw-bold small"><?= e($p['judul']) ?> <span class="badge bg-success-subtle text-success"><?= e($p['kategori']) ?></span></div>
              <div class="small text-muted"><?= e(mb_strimwidth($p['isi'], 0, 90, '...')) ?></div>
            </td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-success py-0 px-2"
                onclick='openPengumumanModal(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus pengumuman ini?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_pengumuman">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= render_pagination($pageP, $totalPagesP) ?>
</div>

<!-- Struktur Organisasi -->
<div class="aka-card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h6 fw-bold text-success mb-0"><i class="bi bi-diagram-3-fill me-1"></i> Struktur Organisasi (<?= count($strukturList) ?>)</h2>
    <button type="button" class="btn btn-sm btn-aka rounded-pill px-3" onclick="openStrukturModal()">
      <i class="bi bi-plus-lg me-1"></i> Tambah
    </button>
  </div>

  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead><tr><th>Urut</th><th>Nama & Jabatan</th><th>HP</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($strukturList as $s): ?>
          <tr>
            <td><?= (int)$s['urutan'] ?></td>
            <td>
              <div class="fw-semibold"><?= e($s['nama']) ?></div>
              <small class="text-success"><?= e($s['jabatan']) ?></small>
            </td>
            <td><?= e($s['no_hp'] ?: '-') ?></td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-success py-0 px-2"
                onclick='openStrukturModal(<?= json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus pengurus ini?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_struktur">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Sambutan & Lokasi Perumahan -->
<div class="aka-card p-3">
  <h2 class="h6 fw-bold text-success mb-3"><i class="bi bi-geo-alt-fill me-1"></i> Sambutan & Lokasi Perumahan (Index)</h2>
  <form method="post" class="row g-2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_lokasi">
    <div class="col-12">
      <label class="form-label small fw-semibold">Teks Sambutan Index</label>
      <input type="text" name="sambutan_index" class="form-control form-control-sm" value="<?= e(get_setting('sambutan_index')) ?>">
    </div>
    <div class="col-12">
      <label class="form-label small fw-semibold">Alamat Perumahan</label>
      <input type="text" name="alamat_perumahan" class="form-control form-control-sm" value="<?= e(get_setting('alamat_perumahan')) ?>">
    </div>
    <div class="col-12 col-md-6">
      <label class="form-label small fw-semibold">Google Maps Embed URL (src iframe)</label>
      <input type="url" name="maps_embed_url" class="form-control form-control-sm" value="<?= e(get_setting('maps_embed_url')) ?>">
    </div>
    <div class="col-12 col-md-6">
      <label class="form-label small fw-semibold">Link Tombol Buka Google Maps</label>
      <input type="url" name="maps_link_url" class="form-control form-control-sm" value="<?= e(get_setting('maps_link_url')) ?>">
    </div>
    <div class="col-12">
      <button type="submit" class="btn btn-sm btn-aka">Simpan Informasi Lokasi</button>
    </div>
  </form>
</div>

<!-- Modal Pengumuman -->
<div class="modal fade" id="pengumumanModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content rounded-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_pengumuman">
      <input type="hidden" name="id" id="pg_id" value="0">
      <div class="modal-header border-0 pb-1">
        <h2 class="h6 fw-bold text-success modal-title" id="pgModalTitle">Tambah Pengumuman</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row g-2 pt-2">
        <div class="col-8">
          <label class="form-label small fw-semibold">Judul</label>
          <input type="text" name="judul" id="pg_judul" class="form-control form-control-sm" required>
        </div>
        <div class="col-4">
          <label class="form-label small fw-semibold">Kategori</label>
          <select name="kategori" id="pg_kat" class="form-select form-select-sm">
            <option value="pengumuman">Pengumuman</option>
            <option value="kegiatan">Kegiatan</option>
            <option value="darurat">Darurat</option>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold">Isi Pengumuman</label>
          <textarea name="isi" id="pg_isi" rows="4" class="form-control form-control-sm" required></textarea>
        </div>
        <div class="col-12 d-flex gap-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_pinned" id="pg_pin">
            <label class="form-check-label small" for="pg_pin">Pin di atas</label>
          </div>
          <div class="form-check" id="pg_notif_wrap">
            <input class="form-check-input" type="checkbox" name="kirim_notif" id="pg_notif" checked>
            <label class="form-check-label small text-success" for="pg_notif">Kirim notifikasi</label>
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

<!-- Modal Struktur Organisasi -->
<div class="modal fade" id="strukturModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" enctype="multipart/form-data" class="modal-content rounded-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_struktur">
      <input type="hidden" name="id" id="st_id" value="0">
      <div class="modal-header border-0 pb-1">
        <h2 class="h6 fw-bold text-success modal-title" id="stModalTitle">Tambah Pengurus</h2>
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
function openPengumumanModal(data = null) {
  document.getElementById('pgModalTitle').textContent = data ? 'Edit Pengumuman' : 'Tambah Pengumuman';
  document.getElementById('pg_id').value = data ? data.id : 0;
  document.getElementById('pg_judul').value = data ? data.judul : '';
  document.getElementById('pg_kat').value = data ? data.kategori : 'pengumuman';
  document.getElementById('pg_isi').value = data ? data.isi : '';
  document.getElementById('pg_pin').checked = data ? (parseInt(data.is_pinned) === 1) : false;
  document.getElementById('pg_notif_wrap').style.display = data ? 'none' : 'block';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('pengumumanModal')).show();
}
function openStrukturModal(data = null) {
  document.getElementById('stModalTitle').textContent = data ? 'Edit Pengurus' : 'Tambah Pengurus';
  document.getElementById('st_id').value = data ? data.id : 0;
  document.getElementById('st_nama').value = data ? data.nama : '';
  document.getElementById('st_jab').value = data ? data.jabatan : '';
  document.getElementById('st_hp').value = data ? (data.no_hp || '') : '';
  document.getElementById('st_urut').value = data ? (data.urutan || 1) : 1;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('strukturModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
