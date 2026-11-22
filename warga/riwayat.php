<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
$user = require_role(['warga', 'admin', 'super_admin']);
$pdo  = db();

$search  = trim($_GET['q'] ?? '');
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$where  = ' WHERE p.user_id = ?';
$params = [(int)$user['id']];
if ($search !== '') {
    $where .= ' AND (p.kode_transaksi LIKE ? OR j.nama_iuran LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like);
}

$cnt = $pdo->prepare("SELECT COUNT(*) FROM pembayaran p JOIN jenis_iuran j ON j.id = p.jenis_iuran_id {$where}");
$cnt->execute($params);
$totalRows  = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$stmt = $pdo->prepare("
    SELECT p.*, j.nama_iuran
    FROM pembayaran p
    JOIN jenis_iuran j ON j.id = p.jenis_iuran_id
    {$where}
    ORDER BY p.tahun DESC, p.bulan DESC, p.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$history = $stmt->fetchAll();

$pageTitle = 'Riwayat Pembayaran';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="aka-card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h6 fw-bold text-success mb-0"><i class="bi bi-clock-history me-1"></i> Riwayat Pembayaran</h1>
      <small class="text-muted">Total: <?= $totalRows ?> transaksi</small>
    </div>
    <a href="<?= e(base_url('warga/bayar.php')) ?>" class="btn btn-sm btn-aka rounded-pill px-3">Bayar Iuran</a>
  </div>

  <form method="get" class="row g-2 mb-3">
    <div class="col-9">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Cari kode atau jenis iuran..." value="<?= e($search) ?>">
    </div>
    <div class="col-3 d-grid">
      <button type="submit" class="btn btn-sm btn-aka-outline">Cari</button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead>
        <tr>
          <th>Kode & Periode</th>
          <th>Iuran</th>
          <th class="text-end">Nominal</th>
          <th class="text-end">Status & Detail</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($history)): ?>
          <tr><td colspan="4" class="text-center text-muted py-3">Belum ada riwayat pembayaran.</td></tr>
        <?php endif; ?>
        <?php foreach ($history as $h): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e(bulan_indo((int)$h['bulan'])) ?> <?= (int)$h['tahun'] ?></div>
              <small class="text-muted"><?= e($h['kode_transaksi']) ?></small>
            </td>
            <td><?= e($h['nama_iuran']) ?></td>
            <td class="text-end fw-semibold text-nowrap"><?= e(rupiah($h['nominal'])) ?></td>
            <td class="text-end text-nowrap">
              <?= badge_pembayaran($h['status']) ?>
              <button type="button" class="btn btn-sm btn-outline-success py-0 px-2 ms-1"
                onclick='openDetailWarga(<?= json_encode($h, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Lihat Detail & Bukti">
                <i class="bi bi-eye"></i>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= render_pagination($page, $totalPages) ?>
</div>

<!-- Modal Detail Pembayaran Warga -->
<div class="modal fade" id="detailWargaModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="h6 modal-title"><i class="bi bi-receipt me-1"></i> Detail Transaksi Pembayaran</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="aka-card-soft p-2 px-3 mb-3 small">
          <div class="fw-bold text-success" id="dw_judul"></div>
          <div class="text-muted" id="dw_info"></div>
          <div class="fw-bold text-dark mt-1" id="dw_nominal"></div>
        </div>
        <div class="text-center">
          <div class="small fw-semibold text-muted mb-1">Bukti Setor / Screenshot Transfer:</div>
          <div id="dw_bukti"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-aka px-4" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<script>
const baseAppUrlW = <?= json_encode(base_url('')) ?>;
function openDetailWarga(d) {
  document.getElementById('dw_judul').textContent = d.nama_iuran;
  document.getElementById('dw_info').textContent = d.kode_transaksi + ' • Metode: ' + (d.metode_bayar || '-').toUpperCase() + (d.catatan ? ' • Catatan: ' + d.catatan : '');
  document.getElementById('dw_nominal').textContent = 'Rp ' + Number(d.nominal).toLocaleString('id-ID');
  const wrap = document.getElementById('dw_bukti');
  if (d.bukti_bayar) {
    const imgUrl = baseAppUrlW + d.bukti_bayar.replace(/^\/+/, '');
    wrap.innerHTML = `<a href="${imgUrl}" target="_blank"><img src="${imgUrl}" alt="Bukti Bayar" class="img-fluid rounded-3 border" style="max-height:260px;"></a>`;
  } else {
    wrap.innerHTML = `<div class="small text-muted py-2">Belum ada foto bukti yang diunggah.</div>`;
  }
  bootstrap.Modal.getOrCreateInstance(document.getElementById('detailWargaModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
