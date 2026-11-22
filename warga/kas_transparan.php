<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
$user = require_role(['warga', 'admin', 'super_admin']);
$pdo  = db();

$bulan   = (int)($_GET['bulan'] ?? date('n'));
$tahun   = (int)($_GET['tahun'] ?? date('Y'));
$search  = trim($_GET['q'] ?? '');
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$allTime = $pdo->query("
    SELECT
      COALESCE(SUM(CASE WHEN jenis = 'pemasukan' THEN nominal ELSE 0 END), 0) AS masuk,
      COALESCE(SUM(CASE WHEN jenis = 'pengeluaran' THEN nominal ELSE 0 END), 0) AS keluar
    FROM keuangan_kas
")->fetch();
$saldoTotal = (float)$allTime['masuk'] - (float)$allTime['keluar'];

$where  = ' WHERE MONTH(tanggal) = ? AND YEAR(tanggal) = ?';
$params = [$bulan, $tahun];
if ($search !== '') {
    $where .= ' AND (kategori LIKE ? OR deskripsi LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like);
}

$cnt = $pdo->prepare("SELECT COUNT(*) FROM keuangan_kas {$where}");
$cnt->execute($params);
$totalRows  = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$stmt = $pdo->prepare("SELECT * FROM keuangan_kas {$where} ORDER BY tanggal DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$mutasi = $stmt->fetchAll();

$pageTitle = 'Transparansi Kas Perumahan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="aka-hero">
  <div class="d-flex justify-content-between align-items-start gap-2">
    <div>
      <div class="small opacity-75">Summary Kas Perumahan Alam Kerato Asri</div>
      <div class="h3 fw-bold mb-1"><?= e(rupiah($saldoTotal)) ?></div>
    </div>
    <a href="<?= e(base_url('admin/laporan.php')) ?>" class="btn btn-sm btn-light text-success fw-semibold rounded-pill px-3">
      <i class="bi bi-bar-chart-line-fill me-1"></i> Laporan Lengkap
    </a>
  </div>
  <div class="row g-2 mt-2 small">
    <div class="col-6">Total Masuk: <strong><?= e(rupiah($allTime['masuk'])) ?></strong></div>
    <div class="col-6">Total Keluar: <strong><?= e(rupiah($allTime['keluar'])) ?></strong></div>
  </div>
</div>

<div class="aka-card p-3">
  <h1 class="h6 fw-bold text-success mb-2"><i class="bi bi-journal-check me-1"></i> Mutasi Pemasukan & Pengeluaran Bendahara</h1>
  <form method="get" class="row g-2 mb-3">
    <div class="col-12 col-md-4">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Cari kategori, uraian..." value="<?= e($search) ?>">
    </div>
    <div class="col-5 col-md-3">
      <select name="bulan" class="form-select form-select-sm">
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $bulan === $m ? 'selected' : '' ?>><?= e(bulan_indo($m)) ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-4 col-md-3">
      <input type="number" name="tahun" class="form-control form-control-sm" value="<?= $tahun ?>">
    </div>
    <div class="col-3 col-md-2 d-grid">
      <button type="submit" class="btn btn-sm btn-aka">Lihat</button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead>
        <tr>
          <th>Tanggal</th>
          <th>Uraian</th>
          <th class="text-end">Nominal</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($mutasi)): ?>
          <tr><td colspan="3" class="text-center text-muted py-3">Belum ada mutasi pada periode ini.</td></tr>
        <?php endif; ?>
        <?php foreach ($mutasi as $m): ?>
          <tr>
            <td class="text-nowrap"><?= e(tanggal_indo($m['tanggal'])) ?></td>
            <td>
              <div class="fw-semibold"><?= e($m['kategori']) ?></div>
              <small class="text-muted"><?= e($m['deskripsi']) ?></small>
              <?php if (!empty($m['bukti_nota'])): ?>
                <div><a href="<?= e(base_url($m['bukti_nota'])) ?>" target="_blank" class="small text-success">Bukti Nota</a></div>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap fw-bold <?= $m['jenis'] === 'pemasukan' ? 'text-success' : 'text-danger' ?>">
              <?= $m['jenis'] === 'pemasukan' ? '+' : '-' ?> <?= e(rupiah($m['nominal'])) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= render_pagination($page, $totalPages) ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
