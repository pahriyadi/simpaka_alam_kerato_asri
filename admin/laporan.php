<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../helpers/format.php';

$user = require_role(['super_admin', 'admin', 'warga']);
$pdo  = db();

$isWarga = ($user['role'] === 'warga');
$adminRt = (in_array($user['role'], ['admin', 'warga'], true) && !empty($user['rt'])) ? $user['rt'] : '';
$adminRw = (in_array($user['role'], ['admin', 'warga'], true) && !empty($user['rw'])) ? $user['rw'] : '';

$mode     = $_GET['mode'] ?? 'tahunan'; // bulanan | triwulan | tahunan
if (!in_array($mode, ['bulanan', 'triwulan', 'tahunan'], true)) {
    $mode = 'tahunan';
}
$bulan    = max(1, min(12, (int)($_GET['bulan'] ?? date('n'))));
$triwulan = max(1, min(4, (int)($_GET['triwulan'] ?? (int)ceil((int)date('n') / 3))));
$tahun    = (int)($_GET['tahun'] ?? date('Y'));
$filterRt = $adminRt !== '' ? $adminRt : trim($_GET['rt'] ?? '');
$filterRw = $adminRw !== '' ? $adminRw : trim($_GET['rw'] ?? '');

// Tentukan rentang bulan sesuai mode
if ($mode === 'bulanan') {
    $monthsRange  = [$bulan];
    $judulPeriode = 'Laporan Bulan ' . bulan_indo($bulan) . " {$tahun}";
    $fileSuffix   = 'bulanan_' . strtolower(bulan_indo($bulan)) . '_' . $tahun;
} elseif ($mode === 'triwulan') {
    $startM       = ($triwulan - 1) * 3 + 1;
    $monthsRange  = [$startM, $startM + 1, $startM + 2];
    $judulPeriode = "Laporan Triwulan {$triwulan} (" . bulan_indo($startM) . ' - ' . bulan_indo($startM + 2) . ") {$tahun}";
    $fileSuffix   = "triwulan_{$triwulan}_{$tahun}";
} else {
    $monthsRange  = range(1, 12);
    $judulPeriode = "Laporan Tahunan {$tahun} (Januari s/d Desember)";
    $fileSuffix   = "tahunan_{$tahun}";
}

$placeholders = implode(',', array_fill(0, count($monthsRange), '?'));

// 1. Summary Kas Keseluruhan
$allTime = $pdo->query("
    SELECT
      COALESCE(SUM(CASE WHEN jenis = 'pemasukan' THEN nominal ELSE 0 END), 0) AS masuk,
      COALESCE(SUM(CASE WHEN jenis = 'pengeluaran' THEN nominal ELSE 0 END), 0) AS keluar
    FROM keuangan_kas
")->fetch();
$saldoTotal = (float)$allTime['masuk'] - (float)$allTime['keluar'];

// 2. Data Pemasukan Iuran Warga (Matrix per Warga x Bulan)
$wargaWhere  = ' WHERE u.role = "warga" AND u.status_aktif = 1';
$wargaParams = [];
if ($filterRt !== '') {
    $wargaWhere .= ' AND COALESCE(wp.rt, u.rt) = ?';
    $wargaParams[] = $filterRt;
}
if ($filterRw !== '') {
    $wargaWhere .= ' AND COALESCE(wp.rw, u.rw) = ?';
    $wargaParams[] = $filterRw;
}

$wargaStmt = $pdo->prepare("
    SELECT u.id, u.nama_lengkap,
           b.nama_blok, b.nomor_rumah,
           COALESCE(wp.rt, u.rt, '01') AS rt,
           COALESCE(wp.rw, u.rw, '01') AS rw
    FROM users u
    LEFT JOIN warga_profil wp ON wp.user_id = u.id
    LEFT JOIN blok_rumah b ON b.id = wp.blok_rumah_id
    {$wargaWhere}
    ORDER BY b.nama_blok ASC, b.nomor_rumah ASC, u.nama_lengkap ASC
");
$wargaStmt->execute($wargaParams);
$wargaList = $wargaStmt->fetchAll();

// Ambil pembayaran LUNAS pada tahun & rentang bulan terpilih
$payParams = array_merge([$tahun], $monthsRange);
$payStmt = $pdo->prepare("
    SELECT user_id, bulan, SUM(nominal) AS total_bayar
    FROM pembayaran
    WHERE status = 'lunas' AND tahun = ? AND bulan IN ({$placeholders})
    GROUP BY user_id, bulan
");
$payStmt->execute($payParams);
$payMap = [];
foreach ($payStmt->fetchAll() as $pr) {
    $payMap[(int)$pr['user_id']][(int)$pr['bulan']] = (float)$pr['total_bayar'];
}

// 3. Ringkasan Kas & Daftar Pengeluaran Bendahara pada Periode Terpilih
$kasParams = array_merge([$tahun], $monthsRange);
$sumKasStmt = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN jenis = 'pemasukan' THEN nominal ELSE 0 END), 0) AS masuk,
      COALESCE(SUM(CASE WHEN jenis = 'pengeluaran' THEN nominal ELSE 0 END), 0) AS keluar
    FROM keuangan_kas
    WHERE YEAR(tanggal) = ? AND MONTH(tanggal) IN ({$placeholders})
");
$sumKasStmt->execute($kasParams);
$ringkasKas = $sumKasStmt->fetch();

$keluarStmt = $pdo->prepare("
    SELECT tanggal, kategori, deskripsi, nominal
    FROM keuangan_kas
    WHERE jenis = 'pengeluaran' AND YEAR(tanggal) = ? AND MONTH(tanggal) IN ({$placeholders})
    ORDER BY tanggal ASC, id ASC
");
$keluarStmt->execute($kasParams);
$daftarPengeluaran = $keluarStmt->fetchAll();

// 4. Ekspor ke Excel (.xls) sesuai mode (Bulanan / Triwulan / Tahunan)
if (($_GET['export'] ?? '') === 'excel') {
    $filename = "Laporan_SIMPAKA_{$fileSuffix}.xls";
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    ?>
    <html>
    <head><meta charset="utf-8"></head>
    <body>
      <h3><?= e(get_setting('nama_perumahan', 'Perumahan Alam Kerato Asri')) ?> - <?= e($judulPeriode) ?></h3>
      <?php if ($filterRt !== '' || $filterRw !== ''): ?>
        <p>Wilayah: RT <?= e($filterRt ?: 'Semua') ?> / RW <?= e($filterRw ?: 'Semua') ?></p>
      <?php endif; ?>

      <h4>1. Laporan Pemasukan Iuran Warga</h4>
      <table border="1" cellspacing="0" cellpadding="4">
        <thead>
          <tr style="background:#EAF6EE;font-weight:bold;">
            <th>NO</th>
            <th>Nama Warga</th>
            <th>Blok</th>
            <th>RT</th>
            <th>RW</th>
            <?php foreach ($monthsRange as $m): ?>
              <th><?= e(bulan_indo($m)) ?></th>
            <?php endforeach; ?>
            <th>Jumlah</th>
            <th>Keterangan</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $no = 1;
            $colTotals = array_fill_keys($monthsRange, 0.0);
            $grandTotalMasuk = 0.0;
            foreach ($wargaList as $w):
                $uid = (int)$w['id'];
                $rowSum = 0.0;
                $paidMonths = 0;
          ?>
            <tr>
              <td><?= $no++ ?></td>
              <td><?= e($w['nama_lengkap']) ?></td>
              <td><?= $w['nama_blok'] ? e($w['nama_blok'] . '.' . $w['nomor_rumah']) : '-' ?></td>
              <td><?= e($w['rt']) ?></td>
              <td><?= e($w['rw']) ?></td>
              <?php foreach ($monthsRange as $m):
                  $val = $payMap[$uid][$m] ?? 0.0;
                  $rowSum += $val;
                  $colTotals[$m] += $val;
                  if ($val > 0) $paidMonths++;
              ?>
                <td><?= $val > 0 ? (int)$val : 0 ?></td>
              <?php endforeach; $grandTotalMasuk += $rowSum; ?>
              <td><?= (int)$rowSum ?></td>
              <td><?= $paidMonths === count($monthsRange) ? 'Lunas Lengkap' : ($paidMonths > 0 ? "{$paidMonths}/" . count($monthsRange) . ' bln' : 'Belum Bayar') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="font-weight:bold;">
            <td colspan="5">Total Pemasukan</td>
            <?php foreach ($monthsRange as $m): ?>
              <td><?= (int)$colTotals[$m] ?></td>
            <?php endforeach; ?>
            <td><?= (int)$grandTotalMasuk ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>

      <br>
      <h4>2. Daftar Pengeluaran Bendahara</h4>
      <table border="1" cellspacing="0" cellpadding="4">
        <thead>
          <tr style="background:#FDECEC;font-weight:bold;">
            <th>NO</th>
            <th>Tanggal</th>
            <th>Kategori</th>
            <th>Keterangan</th>
            <th>Nominal</th>
          </tr>
        </thead>
        <tbody>
          <?php $noK = 1; $totKeluar = 0.0; foreach ($daftarPengeluaran as $dp): $totKeluar += (float)$dp['nominal']; ?>
            <tr>
              <td><?= $noK++ ?></td>
              <td><?= e($dp['tanggal']) ?></td>
              <td><?= e($dp['kategori']) ?></td>
              <td><?= e($dp['deskripsi']) ?></td>
              <td><?= (int)$dp['nominal'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="font-weight:bold;">
            <td colspan="4">Total Pengeluaran</td>
            <td><?= (int)$totKeluar ?></td>
          </tr>
        </tfoot>
      </table>
    </body>
    </html>
    <?php
    exit;
}

$rtOptions = get_rt_list();
$rwOptions = get_rw_list();

$pageTitle = 'Laporan Keuangan';
require_once __DIR__ . '/../includes/header.php';
if (!$isWarga) {
    require_once __DIR__ . '/../includes/admin_nav.php';
} else {
    echo '<div class="aka-subbar no-print"><a href="' . e(base_url('index.php')) . '" class="btn btn-sm btn-aka-outline rounded-pill px-3"><i class="bi bi-arrow-left me-1"></i> Beranda</a><span class="small fw-bold text-success">Laporan Transparansi Kas</span></div>';
}

$exportQuery = http_build_query([
    'mode'     => $mode,
    'bulan'    => $bulan,
    'triwulan' => $triwulan,
    'tahun'    => $tahun,
    'rt'       => $filterRt,
    'rw'       => $filterRw,
    'export'   => 'excel',
]);
?>

<div class="aka-card p-3 no-print">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h6 fw-bold text-success mb-0"><i class="bi bi-bar-chart-line-fill me-1"></i> Laporan Keuangan Perumahan</h1>
    <div class="d-flex gap-2">
      <a href="?<?= e($exportQuery) ?>" class="btn btn-sm btn-aka-outline rounded-pill px-3">
        <i class="bi bi-file-earmark-excel me-1"></i> Ekspor Excel
      </a>
      <button type="button" onclick="window.print()" class="btn btn-sm btn-aka rounded-pill px-3">
        <i class="bi bi-printer me-1"></i> Cetak / PDF
      </button>
    </div>
  </div>

  <form method="get" class="row g-2">
    <div class="col-6 col-md-3">
      <select name="mode" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="bulanan" <?= $mode === 'bulanan' ? 'selected' : '' ?>>Bulanan</option>
        <option value="triwulan" <?= $mode === 'triwulan' ? 'selected' : '' ?>>Triwulan</option>
        <option value="tahunan" <?= $mode === 'tahunan' ? 'selected' : '' ?>>Tahunan</option>
      </select>
    </div>

    <?php if ($mode === 'bulanan'): ?>
      <div class="col-6 col-md-3">
        <select name="bulan" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $bulan === $m ? 'selected' : '' ?>><?= e(bulan_indo($m)) ?></option>
          <?php endfor; ?>
        </select>
      </div>
    <?php elseif ($mode === 'triwulan'): ?>
      <div class="col-6 col-md-3">
        <select name="triwulan" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="1" <?= $triwulan === 1 ? 'selected' : '' ?>>Triwulan 1 (Jan-Mar)</option>
          <option value="2" <?= $triwulan === 2 ? 'selected' : '' ?>>Triwulan 2 (Apr-Jun)</option>
          <option value="3" <?= $triwulan === 3 ? 'selected' : '' ?>>Triwulan 3 (Jul-Sep)</option>
          <option value="4" <?= $triwulan === 4 ? 'selected' : '' ?>>Triwulan 4 (Okt-Des)</option>
        </select>
      </div>
    <?php endif; ?>

    <div class="col-4 col-md-2">
      <input type="number" name="tahun" class="form-control form-control-sm" value="<?= $tahun ?>">
    </div>

    <?php if ($adminRt === '' && $adminRw === ''): ?>
      <div class="col-4 col-md-2">
        <select name="rt" class="form-select form-select-sm">
          <option value="">All RT</option>
          <?php foreach ($rtOptions as $rtOpt): ?>
            <option value="<?= e($rtOpt) ?>" <?= $filterRt === $rtOpt ? 'selected' : '' ?>>RT <?= e($rtOpt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-4 col-md-1">
        <select name="rw" class="form-select form-select-sm">
          <option value="">RW</option>
          <?php foreach ($rwOptions as $rwOpt): ?>
            <option value="<?= e($rwOpt) ?>" <?= $filterRw === $rwOpt ? 'selected' : '' ?>><?= e($rwOpt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <div class="col-12 col-md-1 d-grid">
      <button type="submit" class="btn btn-sm btn-aka-outline">Lihat</button>
    </div>
  </form>
</div>

<!-- Header Laporan + Ringkasan Kas -->
<div class="aka-card p-3">
  <div class="text-center border-bottom pb-2 mb-3">
    <div class="fw-bold text-success"><?= e(get_setting('nama_perumahan', 'Perumahan Alam Kerato Asri')) ?></div>
    <div class="small fw-semibold"><?= e($judulPeriode) ?></div>
    <?php if ($filterRt !== '' || $filterRw !== ''): ?>
      <div class="small text-muted">Wilayah: RT <?= e($filterRt ?: 'Semua') ?> / RW <?= e($filterRw ?: 'Semua') ?></div>
    <?php endif; ?>
    <div class="small text-muted">Saldo Kas Keseluruhan Saat Ini: <strong class="text-success"><?= e(rupiah($saldoTotal)) ?></strong></div>
  </div>

  <div class="row g-2 mb-3">
    <div class="col-4">
      <div class="aka-card-soft p-2 text-center">
        <div class="small text-muted">Pemasukan Kas</div>
        <div class="fw-bold text-success small"><?= e(rupiah($ringkasKas['masuk'])) ?></div>
      </div>
    </div>
    <div class="col-4">
      <div class="aka-card-soft p-2 text-center">
        <div class="small text-muted">Pengeluaran Kas</div>
        <div class="fw-bold text-danger small"><?= e(rupiah($ringkasKas['keluar'])) ?></div>
      </div>
    </div>
    <div class="col-4">
      <div class="aka-card-soft p-2 text-center">
        <div class="small text-muted">Selisih Periode</div>
        <?php $net = (float)$ringkasKas['masuk'] - (float)$ringkasKas['keluar']; ?>
        <div class="fw-bold small <?= $net >= 0 ? 'text-success' : 'text-danger' ?>"><?= e(rupiah($net)) ?></div>
      </div>
    </div>
  </div>

  <!-- 1. Tabel Matrix Pemasukan Iuran Warga -->
  <h2 class="h6 fw-bold text-success mb-2"><i class="bi bi-table me-1"></i> Laporan Pemasukan Iuran Warga</h2>
  <div class="table-responsive mb-4">
    <table class="table table-sm table-bordered table-aka mb-0">
      <thead>
        <tr>
          <th class="text-center">NO</th>
          <th>Nama Warga</th>
          <th>Blok</th>
          <th class="text-center">RT</th>
          <th class="text-center">RW</th>
          <?php foreach ($monthsRange as $m): ?>
            <th class="text-end text-nowrap"><?= e(substr(bulan_indo($m), 0, 3)) ?></th>
          <?php endforeach; ?>
          <th class="text-end">Jumlah</th>
          <th>Keterangan</th>
        </tr>
      </thead>
      <tbody>
        <?php
          $no = 1;
          $colTotals = array_fill_keys($monthsRange, 0.0);
          $grandTotalMasuk = 0.0;
          if (empty($wargaList)):
        ?>
          <tr><td colspan="<?= 7 + count($monthsRange) ?>" class="text-center text-muted py-3">Belum ada data warga pada filter ini.</td></tr>
        <?php endif; ?>
        <?php foreach ($wargaList as $w): ?>
          <?php
            $uid = (int)$w['id'];
            $rowSum = 0.0;
            $paidMonths = 0;
          ?>
          <tr>
            <td class="text-center"><?= $no++ ?></td>
            <td class="text-nowrap fw-semibold"><?= e($w['nama_lengkap']) ?></td>
            <td class="text-nowrap text-success fw-bold"><?= $w['nama_blok'] ? e($w['nama_blok'] . '.' . $w['nomor_rumah']) : '-' ?></td>
            <td class="text-center"><?= e($w['rt']) ?></td>
            <td class="text-center"><?= e($w['rw']) ?></td>
            <?php foreach ($monthsRange as $m): ?>
              <?php
                $val = $payMap[$uid][$m] ?? 0.0;
                $rowSum += $val;
                $colTotals[$m] += $val;
                if ($val > 0) $paidMonths++;
              ?>
              <td class="text-end text-nowrap <?= $val > 0 ? 'text-success fw-semibold' : 'text-muted' ?>">
                <?= $val > 0 ? number_format($val, 0, ',', '.') : '-' ?>
              </td>
            <?php endforeach; ?>
            <?php $grandTotalMasuk += $rowSum; ?>
            <td class="text-end text-nowrap fw-bold text-success"><?= e(rupiah($rowSum)) ?></td>
            <td class="text-nowrap small">
              <?php if ($paidMonths === count($monthsRange)): ?>
                <span class="badge bg-success-subtle text-success">Lunas Lengkap</span>
              <?php elseif ($paidMonths > 0): ?>
                <span class="badge bg-warning-subtle text-warning-emphasis"><?= $paidMonths ?>/<?= count($monthsRange) ?> bln</span>
              <?php else: ?>
                <span class="badge bg-danger-subtle text-danger">Belum Bayar</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if (!empty($wargaList)): ?>
        <tfoot>
          <tr class="table-success fw-bold">
            <td colspan="5" class="text-end">Total Pemasukan</td>
            <?php foreach ($monthsRange as $m): ?>
              <td class="text-end text-nowrap"><?= number_format($colTotals[$m], 0, ',', '.') ?></td>
            <?php endforeach; ?>
            <td class="text-end text-nowrap"><?= e(rupiah($grandTotalMasuk)) ?></td>
            <td></td>
          </tr>
        </tfoot>
      <?php endif; ?>
    </table>
  </div>

  <!-- 2. Daftar Pengeluaran Bendahara -->
  <h2 class="h6 fw-bold text-danger mb-2"><i class="bi bi-list-ul me-1"></i> Daftar Pengeluaran Bendahara</h2>
  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead>
        <tr>
          <th>NO</th>
          <th>Tanggal</th>
          <th>Kategori &amp; Keterangan Pengeluaran</th>
          <th class="text-end">Nominal</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($daftarPengeluaran)): ?>
          <tr><td colspan="4" class="text-center text-muted py-3">Tidak ada pengeluaran pada periode ini.</td></tr>
        <?php endif; ?>
        <?php
          $noK = 1;
          $totKeluar = 0.0;
          foreach ($daftarPengeluaran as $dp):
            $totKeluar += (float)$dp['nominal'];
        ?>
          <tr>
            <td><?= $noK++ ?></td>
            <td class="text-nowrap"><?= e(tanggal_indo($dp['tanggal'])) ?></td>
            <td>
              <div class="fw-semibold"><?= e($dp['kategori']) ?></div>
              <small class="text-muted"><?= e($dp['deskripsi']) ?></small>
            </td>
            <td class="text-end text-nowrap fw-bold text-danger"><?= e(rupiah($dp['nominal'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if (!empty($daftarPengeluaran)): ?>
        <tfoot>
          <tr class="table-danger fw-bold">
            <td colspan="3" class="text-end">Total Pengeluaran</td>
            <td class="text-end text-nowrap"><?= e(rupiah($totKeluar)) ?></td>
          </tr>
        </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
