<?php
declare(strict_types=1);

$pageTitle = 'Beranda';
require_once __DIR__ . '/includes/header.php';

$pdo = db();

// Catat kunjungan harian & ambil statistik 7 hari terakhir
$kunjunganHariIni  = 0;
$pengunjungHariIni = 0;
$totalKunjungan    = 0;
$visitLabels       = [];
$visitHits         = [];
try {
    $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $pdo->prepare('INSERT INTO kunjungan (tanggal, ip_hash, hits) VALUES (CURDATE(), ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1')
        ->execute([$ipHash]);

    $todayRow = $pdo->query('SELECT COALESCE(SUM(hits), 0) AS hits, COUNT(*) AS uniq FROM kunjungan WHERE tanggal = CURDATE()')->fetch();
    $kunjunganHariIni  = (int)($todayRow['hits'] ?? 0);
    $pengunjungHariIni = (int)($todayRow['uniq'] ?? 0);
    $totalKunjungan    = (int)$pdo->query('SELECT COALESCE(SUM(hits), 0) FROM kunjungan')->fetchColumn();

    $vStmt = $pdo->query('SELECT tanggal, COALESCE(SUM(hits), 0) AS hits FROM kunjungan WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY tanggal');
    $vMap  = [];
    foreach ($vStmt->fetchAll() as $vr) {
        $vMap[$vr['tanggal']] = (int)$vr['hits'];
    }
    for ($d = 6; $d >= 0; $d--) {
        $tglKey        = date('Y-m-d', strtotime("-{$d} days"));
        $visitLabels[] = date('d/m', strtotime($tglKey));
        $visitHits[]   = $vMap[$tglKey] ?? 0;
    }
} catch (Throwable $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS kunjungan (id INT UNSIGNED NOT NULL AUTO_INCREMENT, tanggal DATE NOT NULL, ip_hash VARCHAR(64) NOT NULL, hits INT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY (id), UNIQUE KEY uk_tgl_ip (tanggal, ip_hash), KEY idx_kunjungan_tgl (tanggal)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e2) {
        // Abaikan jika tidak ada hak CREATE TABLE
    }
}

// Handle aksi Tegur / Sapa ajak bayar iuran (khusus user login)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sapa_iuran') {
    if (!$user) {
        set_flash('warning', 'Silakan login terlebih dahulu.');
        redirect('login.php');
    }
    verify_csrf();
    $targetPayId = (int)($_POST['pembayaran_id'] ?? 0);
    if ($targetPayId > 0) {
        $pStmt = $pdo->prepare('
            SELECT p.id, p.user_id, p.bulan, p.tahun, p.nominal, b.nama_blok, b.nomor_rumah, j.nama_iuran
            FROM pembayaran p
            LEFT JOIN blok_rumah b ON b.id = p.blok_rumah_id
            JOIN jenis_iuran j ON j.id = p.jenis_iuran_id
            WHERE p.id = ? AND p.status IN ("belum_bayar", "ditolak")
            LIMIT 1
        ');
        $pStmt->execute([$targetPayId]);
        $target = $pStmt->fetch();
        if ($target) {
            $blokTxt  = $target['nama_blok'] ? "Blok {$target['nama_blok']}.{$target['nomor_rumah']}" : 'Rumah Anda';
            $pengirim = is_admin_or_super($user) ? 'Pengurus RT/RW' : ($user['nama_blok'] ? "Tetangga Blok {$user['nama_blok']}.{$user['nomor_rumah']}" : 'Warga AKA');
            $judul    = 'Sapaan Pengingat Iuran Bulanan';
            $pesan    = "Assalamu'alaikum / Salam hangat dari {$pengirim}. Yuk bersama-sama melunasi {$target['nama_iuran']} ({$blokTxt}) periode " . bulan_indo((int)$target['bulan']) . " {$target['tahun']} sebesar " . rupiah($target['nominal']) . " demi kenyamanan Perumahan Alam Kerato Asri.";
            create_notification((int)$target['user_id'], $judul, $pesan, 'tagihan_bulanan', true);
            log_activity('Tegur / Sapa Iuran', "{$blokTxt} oleh {$user['nama_lengkap']}");
            set_flash('success', "Sapaan pengingat iuran berhasil dikirim ke {$blokTxt}.");
        }
    }
    redirect('index.php#belum-bayar-wilayah');
}

// Summary Kas Perumahan
$kasSummary = $pdo->query("
    SELECT
      COALESCE(SUM(CASE WHEN jenis = 'pemasukan' THEN nominal ELSE 0 END), 0) AS total_masuk,
      COALESCE(SUM(CASE WHEN jenis = 'pengeluaran' THEN nominal ELSE 0 END), 0) AS total_keluar
    FROM keuangan_kas
")->fetch();
$saldoKas = (float)$kasSummary['total_masuk'] - (float)$kasSummary['total_keluar'];

$totalRumah = (int)$pdo->query('SELECT COUNT(*) FROM blok_rumah')->fetchColumn();
$totalWarga = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'warga' AND status_aktif = 1")->fetchColumn();

$pengumumanList = $pdo->query('SELECT * FROM pengumuman ORDER BY is_pinned DESC, created_at DESC LIMIT 6')->fetchAll();
$strukturList   = $pdo->query('SELECT * FROM struktur_organisasi ORDER BY urutan ASC, id ASC')->fetchAll();

// Informasi Publik Wilayah RT/RW & Blok Rumah (tanpa data pribadi)
$wilayahPublik = $pdo->query("
    SELECT r.rt, r.rw, r.cakupan_blok, r.keterangan,
           (SELECT COUNT(*) FROM blok_rumah b WHERE b.rt = r.rt AND b.rw = r.rw) AS jml_unit,
           COALESCE(
             (SELECT u.nama_lengkap FROM users u WHERE u.role = 'admin' AND u.status_aktif = 1 AND u.rt = r.rt AND u.rw = r.rw ORDER BY u.id ASC LIMIT 1),
             (SELECT so.nama FROM struktur_organisasi so WHERE so.rt = r.rt AND so.rw = r.rw ORDER BY so.urutan ASC, so.id ASC LIMIT 1)
           ) AS nama_rt,
           COALESCE(
             (SELECT u.no_hp FROM users u WHERE u.role = 'admin' AND u.status_aktif = 1 AND u.rt = r.rt AND u.rw = r.rw ORDER BY u.id ASC LIMIT 1),
             (SELECT so.no_hp FROM struktur_organisasi so WHERE so.rt = r.rt AND so.rw = r.rw ORDER BY so.urutan ASC, so.id ASC LIMIT 1)
           ) AS hp_rt
    FROM referensi_rt_rw r
    WHERE r.aktif = 1
    ORDER BY r.rw ASC, r.rt ASC
")->fetchAll();

// Detail blok per RT/RW untuk Modal "Lihat Detail Blok" (tanpa data pribadi pemilik)
$allBlokPublik = $pdo->query("SELECT nama_blok, nomor_rumah, rt, rw, status_hunian FROM blok_rumah ORDER BY nama_blok ASC, nomor_rumah ASC")->fetchAll();
$blokByWilayah = [];
foreach ($allBlokPublik as $ab) {
    $key = $ab['rt'] . '-' . $ab['rw'];
    $blokByWilayah[$key][] = $ab;
}

$qBlokPublik = trim($_GET['qb'] ?? '');
$rtPublik    = trim($_GET['rtb'] ?? '');
$perPageBlok = 8;
$pageBlok    = max(1, (int)($_GET['page'] ?? 1));
$offsetBlok  = ($pageBlok - 1) * $perPageBlok;

$whereBlok  = ' WHERE 1=1';
$paramsBlok = [];
if ($qBlokPublik !== '') {
    $whereBlok .= ' AND (nama_blok LIKE ? OR nomor_rumah LIKE ?)';
    $like = "%{$qBlokPublik}%";
    array_push($paramsBlok, $like, $like);
}
if ($rtPublik !== '') {
    $whereBlok .= ' AND rt = ?';
    $paramsBlok[] = $rtPublik;
}
$cntBlok = $pdo->prepare("SELECT COUNT(*) FROM blok_rumah {$whereBlok}");
$cntBlok->execute($paramsBlok);
$totalBlokPublik = (int)$cntBlok->fetchColumn();
$totalPagesBlok  = max(1, (int)ceil($totalBlokPublik / $perPageBlok));
if ($pageBlok > $totalPagesBlok) {
    $pageBlok = $totalPagesBlok;
    $offsetBlok = ($pageBlok - 1) * $perPageBlok;
}
$stmtBlok = $pdo->prepare("SELECT nama_blok, nomor_rumah, rt, rw, status_hunian FROM blok_rumah {$whereBlok} ORDER BY nama_blok ASC, nomor_rumah ASC LIMIT {$perPageBlok} OFFSET {$offsetBlok}");
$stmtBlok->execute($paramsBlok);
$blokPublikList = $stmtBlok->fetchAll();
$rtFilterOpts   = get_rt_list();

$namaPerumahan   = get_setting('nama_perumahan', 'Perumahan Alam Kerato Asri (AKA)');
$alamatPerumahan = get_setting('alamat_perumahan', 'Alam Kerato Asri');
$sambutanIndex   = get_setting('sambutan_index', '');
$mapsEmbedUrl    = get_setting('maps_embed_url', '');
$mapsLinkUrl     = get_setting('maps_link_url', '#');
$tampilKasPublik = get_setting('tampilkan_kas_publik', '1') === '1';

// Data tambahan ketika login (menggantikan halaman dashboard terpisah)
$bulanIni = (int)date('n');
$tahunIni = (int)date('Y');
$chartLabels = [];
$chartMasuk  = [];
$chartKeluar = [];

if ($user) {
    // Data Chart 6 bulan terakhir
    for ($i = 5; $i >= 0; $i--) {
        $ts = strtotime("-{$i} months");
        $m  = (int)date('n', $ts);
        $y  = (int)date('Y', $ts);
        $chartLabels[] = substr(bulan_indo($m), 0, 3) . ' ' . substr((string)$y, 2);

        $cStmt = $pdo->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN jenis = 'pemasukan' THEN nominal ELSE 0 END), 0) AS masuk,
              COALESCE(SUM(CASE WHEN jenis = 'pengeluaran' THEN nominal ELSE 0 END), 0) AS keluar
            FROM keuangan_kas
            WHERE MONTH(tanggal) = ? AND YEAR(tanggal) = ?
        ");
        $cStmt->execute([$m, $y]);
        $cRow = $cStmt->fetch();
        $chartMasuk[]  = (float)$cRow['masuk'];
        $chartKeluar[] = (float)$cRow['keluar'];
    }

    if (is_admin_or_super($user)) {
        $adminRt = ($user['role'] === 'admin' && !empty($user['rt'])) ? $user['rt'] : '';
        $adminRw = ($user['role'] === 'admin' && !empty($user['rw'])) ? $user['rw'] : '';

        $rtFilterRumah = '';
        $rtFilterWarga = '';
        $rtParams      = [];
        if ($adminRt !== '') {
            $rtFilterRumah .= ' AND rt = ?';
            $rtFilterWarga .= ' AND COALESCE(wp.rt, u.rt) = ?';
            $rtParams[]     = $adminRt;
        }
        if ($adminRw !== '') {
            $rtFilterRumah .= ' AND rw = ?';
            $rtFilterWarga .= ' AND COALESCE(wp.rw, u.rw) = ?';
            $rtParams[]     = $adminRw;
        }

        $rStmt = $pdo->prepare("SELECT COUNT(*) FROM blok_rumah WHERE 1=1 {$rtFilterRumah}");
        $rStmt->execute($rtParams);
        $totalRumahWilayah = (int)$rStmt->fetchColumn();

        $wStmt = $pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN warga_profil wp ON wp.user_id = u.id WHERE u.role = 'warga' AND u.status_aktif = 1 {$rtFilterWarga}");
        $wStmt->execute($rtParams);
        $totalWargaWilayah = (int)$wStmt->fetchColumn();

        $kaStmt = $pdo->prepare("SELECT COUNT(*) FROM warga_profil wp JOIN users u ON u.id = wp.user_id WHERE u.status_aktif = 1 AND wp.status_ktp = 'penduduk_asli_perumahan' {$rtFilterWarga}");
        $kaStmt->execute($rtParams);
        $ktpAsli = (int)$kaStmt->fetchColumn();

        $kdStmt = $pdo->prepare("SELECT COUNT(*) FROM warga_profil wp JOIN users u ON u.id = wp.user_id WHERE u.status_aktif = 1 AND wp.status_ktp = 'domisili_luar' {$rtFilterWarga}");
        $kdStmt->execute($rtParams);
        $ktpDomisili = (int)$kdStmt->fetchColumn();

        $kasBulanStmt = $pdo->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN jenis = 'pemasukan' THEN nominal ELSE 0 END), 0) AS masuk,
              COALESCE(SUM(CASE WHEN jenis = 'pengeluaran' THEN nominal ELSE 0 END), 0) AS keluar
            FROM keuangan_kas
            WHERE MONTH(tanggal) = ? AND YEAR(tanggal) = ?
        ");
        $kasBulanStmt->execute([$bulanIni, $tahunIni]);
        $kasBulan      = $kasBulanStmt->fetch();
        $mvStmt = $pdo->prepare("SELECT COUNT(*) FROM pembayaran p JOIN users u ON u.id = p.user_id LEFT JOIN warga_profil wp ON wp.user_id = u.id WHERE p.status = 'menunggu_verifikasi' {$rtFilterWarga}");
        $mvStmt->execute($rtParams);
        $menungguVerif = (int)$mvStmt->fetchColumn();

        $bbStmt = $pdo->prepare("SELECT COUNT(*) FROM pembayaran p JOIN users u ON u.id = p.user_id LEFT JOIN warga_profil wp ON wp.user_id = u.id WHERE p.bulan = {$bulanIni} AND p.tahun = {$tahunIni} AND p.status = 'belum_bayar' {$rtFilterWarga}");
        $bbStmt->execute($rtParams);
        $belumBayarBln = (int)$bbStmt->fetchColumn();

        $aspirasiBaru  = (int)$pdo->query("SELECT COUNT(*) FROM aspirasi WHERE status = 'baru'")->fetchColumn();
        $recentKas     = $pdo->query('SELECT * FROM keuangan_kas ORDER BY tanggal DESC, id DESC LIMIT 5')->fetchAll();
    } else {
        $tagihanStmt = $pdo->prepare("
            SELECT COUNT(*) AS jml, COALESCE(SUM(nominal), 0) AS total
            FROM pembayaran
            WHERE user_id = ? AND status IN ('belum_bayar', 'ditolak')
        ");
        $tagihanStmt->execute([(int)$user['id']]);
        $tagihanWarga = $tagihanStmt->fetch();
        $notifsWarga  = get_user_notifications((int)$user['id'], 5);
    }

    // Daftar blok di wilayah RT/RW user yang belum membayar bulan ini
    $userRt = $user['rt'] ?? '';
    $userRw = $user['rw'] ?? '';
    $belumBayarSql = "
        SELECT p.id AS pembayaran_id, p.nominal, p.bulan, p.tahun,
               b.nama_blok, b.nomor_rumah, COALESCE(b.rt, u.rt) AS rt, COALESCE(b.rw, u.rw) AS rw,
               j.nama_iuran, u.no_hp
        FROM pembayaran p
        JOIN users u ON u.id = p.user_id
        LEFT JOIN blok_rumah b ON b.id = p.blok_rumah_id
        JOIN jenis_iuran j ON j.id = p.jenis_iuran_id
        WHERE p.bulan = ? AND p.tahun = ? AND p.status IN ('belum_bayar', 'ditolak')
    ";
    $bbParams = [$bulanIni, $tahunIni];
    if ($user['role'] !== 'super_admin' && $userRt !== '' && $userRw !== '') {
        $belumBayarSql .= ' AND COALESCE(b.rt, u.rt) = ? AND COALESCE(b.rw, u.rw) = ?';
        $bbParams[] = $userRt;
        $bbParams[] = $userRw;
    }
    $belumBayarSql .= ' ORDER BY b.nama_blok ASC, b.nomor_rumah ASC LIMIT 12';
    $bbWilStmt = $pdo->prepare($belumBayarSql);
    $bbWilStmt->execute($bbParams);
    $belumBayarWilayah = $bbWilStmt->fetchAll();

    // Rekap jumlah iuran LUNAS masing-masing RT dan gabungan per RW (dapat dilihat semua user, admin, super admin)
    $rekapRw = [];
    $rtIuranStmt = $pdo->query("
        SELECT r.rt, r.rw,
               COALESCE(SUM(CASE WHEN p.status = 'lunas' THEN p.nominal ELSE 0 END), 0) AS total_iuran,
               COALESCE(SUM(CASE WHEN p.status = 'lunas' AND p.bulan = {$bulanIni} AND p.tahun = {$tahunIni} THEN p.nominal ELSE 0 END), 0) AS bulan_ini
        FROM referensi_rt_rw r
        LEFT JOIN users u ON u.role = 'warga' AND u.status_aktif = 1 AND u.rt = r.rt AND u.rw = r.rw
        LEFT JOIN warga_profil wp ON wp.user_id = u.id
        LEFT JOIN pembayaran p ON p.user_id = u.id
        WHERE r.aktif = 1
        GROUP BY r.rw, r.rt
        ORDER BY r.rw ASC, r.rt ASC
    ");
    foreach ($rtIuranStmt->fetchAll() as $rr) {
        $rwStr = (string)$rr['rw'];
        if (!isset($rekapRw[$rwStr])) {
            $rekapRw[$rwStr] = ['rw' => $rwStr, 'total_rw' => 0.0, 'bulan_rw' => 0.0, 'rt_list' => []];
        }
        $rekapRw[$rwStr]['total_rw']  += (float)$rr['total_iuran'];
        $rekapRw[$rwStr]['bulan_rw']  += (float)$rr['bulan_ini'];
        $rekapRw[$rwStr]['rt_list'][]  = $rr;
    }
}
?>

<!-- Hero Card ala DANA / BRImo -->
<section class="aka-hero">
  <div class="d-flex justify-content-between align-items-start gap-2">
    <div>
      <?php if ($user): ?>
        <span class="badge bg-white text-success rounded-pill px-3 py-1 mb-2 fw-semibold">
          <i class="bi bi-person-check-fill me-1"></i>
          <?= is_admin_or_super($user) ? e(role_label($user['role'])) : ($user['nama_blok'] ? 'Blok ' . e($user['nama_blok'] . '.' . $user['nomor_rumah']) : 'Warga AKA') ?>
        </span>
        <h1 class="h5 fw-bold mb-1">Halo, <?= e($user['nama_lengkap']) ?></h1>
        <p class="small mb-0 opacity-90"><?= e($namaPerumahan) ?></p>
      <?php else: ?>
        <span class="badge bg-white text-success rounded-pill px-3 py-1 mb-2 fw-semibold">
          <i class="bi bi-tree-fill me-1"></i> Portal Resmi Warga
        </span>
        <h1 class="h5 fw-bold mb-1"><?= e($namaPerumahan) ?></h1>
        <p class="small mb-1 opacity-90"><?= e($sambutanIndex) ?></p>
        <div class="small opacity-75">
          <i class="bi bi-geo-alt-fill me-1"></i> <?= e($alamatPerumahan) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($user): ?>
    <div class="aka-wallet-card">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <div class="small text-muted fw-semibold">Saldo Kas Perumahan</div>
          <div class="h4 fw-bold text-success mb-0"><?= e(rupiah($saldoKas)) ?></div>
        </div>
        <div class="text-end small">
          <?php if (is_admin_or_super($user)): ?>
            <div class="text-success fw-semibold">+ <?= e(rupiah($kasBulan['masuk'])) ?></div>
            <div class="text-danger fw-semibold">- <?= e(rupiah($kasBulan['keluar'])) ?></div>
          <?php else: ?>
            <div class="text-muted">Tagihan Anda:</div>
            <div class="fw-bold <?= (int)$tagihanWarga['jml'] > 0 ? 'text-danger' : 'text-success' ?>">
              <?= e(rupiah($tagihanWarga['total'])) ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</section>

<?php if ($user): ?>
  <!-- Menu Manajemen SIMPAKA Lengkap (Hanya tampil ketika login) -->
  <section class="aka-card p-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="h6 fw-bold text-success mb-0">
        <i class="bi bi-grid-fill me-1"></i>
        <?= is_admin_or_super($user) ? 'Menu Manajemen SIMPAKA' : 'Layanan Warga AKA' ?>
      </h2>
      <a href="<?= e(base_url('logout.php')) ?>" class="small text-danger text-decoration-none fw-semibold">
        <i class="bi bi-box-arrow-right"></i> Keluar
      </a>
    </div>

    <?php if (is_admin_or_super($user)): ?>
      <div class="quick-grid">
        <a href="<?= e(base_url('admin/blok_rumah.php')) ?>" class="quick-item">
          <div class="quick-icon"><i class="bi bi-houses-fill"></i></div>
          <span class="quick-label">Blok Rumah</span>
        </a>
        <a href="<?= e(base_url('admin/wilayah.php')) ?>" class="quick-item">
          <div class="quick-icon"><i class="bi bi-map-fill"></i></div>
          <span class="quick-label">Wilayah RT/RW</span>
        </a>
        <a href="<?= e(base_url('admin/warga.php')) ?>" class="quick-item">
          <div class="quick-icon"><i class="bi bi-people-fill"></i></div>
          <span class="quick-label">Data Warga</span>
        </a>
        <a href="<?= e(base_url('admin/pembayaran.php')) ?>" class="quick-item">
          <div class="quick-icon"><i class="bi bi-receipt-cutoff"></i></div>
          <span class="quick-label">Pembayaran</span>
        </a>
        <a href="<?= e(base_url('admin/keuangan.php')) ?>" class="quick-item">
          <div class="quick-icon"><i class="bi bi-cash-coin"></i></div>
          <span class="quick-label">Catat Kas</span>
        </a>
        <a href="<?= e(base_url('admin/laporan.php')) ?>" class="quick-item">
          <div class="quick-icon"><i class="bi bi-bar-chart-line-fill"></i></div>
          <span class="quick-label">Laporan Kas</span>
        </a>
        <a href="<?= e(base_url('admin/cms_info.php')) ?>" class="quick-item">
          <div class="quick-icon"><i class="bi bi-megaphone-fill"></i></div>
          <span class="quick-label">Info Index</span>
        </a>
        <a href="<?= e(base_url('admin/aspirasi.php')) ?>" class="quick-item">
          <div class="quick-icon"><i class="bi bi-chat-dots-fill"></i></div>
          <span class="quick-label">Aspirasi (<?= $aspirasiBaru ?>)</span>
        </a>
        <?php if (is_super_admin($user)): ?>
          <a href="<?= e(base_url('admin/settings.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-gear-fill"></i></div>
            <span class="quick-label">Settings Web</span>
          </a>
        <?php else: ?>
          <a href="<?= e(base_url('warga/notifikasi.php')) ?>" class="quick-item">
            <div class="quick-icon"><i class="bi bi-bell-fill"></i></div>
            <span class="quick-label">Notifikasi</span>
          </a>
        <?php endif; ?>
        <a href="#" class="quick-item pwa-install-menu-item" onclick="event.preventDefault(); triggerPwaInstall();">
          <div class="quick-icon"><i class="bi bi-download"></i></div>
          <span class="quick-label">Download App</span>
        </a>
      </div>
    <?php else: ?>
      <div class="quick-grid">
        <a href="<?= e(base_url('warga/bayar.php')) ?>" class="quick-item">
          <div class="quick-icon"><i class="bi bi-credit-card-fill"></i></div>
          <span class="quick-label">Bayar Iuran</span>
        </a>
        <a href="<?= e(base_url('warga/riwayat.php')) ?>" class="quick-item">
          <div class="quick-icon"><i class="bi bi-clock-history"></i></div>
          <span class="quick-label">Riwayat</span>
        </a>
        <a href="<?= e(base_url('warga/kas_transparan.php')) ?>" class="quick-item">
          <div class="quick-icon"><i class="bi bi-cash-stack"></i></div>
          <span class="quick-label">Kas AKA</span>
        </a>
        <a href="<?= e(base_url('admin/laporan.php')) ?>" class="quick-item">
          <div class="quick-icon"><i class="bi bi-bar-chart-line-fill"></i></div>
          <span class="quick-label">Laporan Kas</span>
        </a>
        <a href="<?= e(base_url('warga/notifikasi.php')) ?>" class="quick-item">
          <div class="quick-icon"><i class="bi bi-bell-fill"></i></div>
          <span class="quick-label">Notifikasi</span>
        </a>
        <a href="<?= e(base_url('warga/profil.php')) ?>" class="quick-item">
          <div class="quick-icon"><i class="bi bi-person-badge-fill"></i></div>
          <span class="quick-label">Profil & Aduan</span>
        </a>
        <a href="#pengumuman" class="quick-item">
          <div class="quick-icon"><i class="bi bi-megaphone-fill"></i></div>
          <span class="quick-label">Pengumuman</span>
        </a>
        <a href="#struktur" class="quick-item">
          <div class="quick-icon"><i class="bi bi-people-fill"></i></div>
          <span class="quick-label">Pengurus</span>
        </a>
        <a href="#lokasi" class="quick-item">
          <div class="quick-icon"><i class="bi bi-geo-alt-fill"></i></div>
          <span class="quick-label">Lokasi</span>
        </a>
        <a href="#" class="quick-item pwa-install-menu-item" onclick="event.preventDefault(); triggerPwaInstall();">
          <div class="quick-icon"><i class="bi bi-download"></i></div>
          <span class="quick-label">Download App</span>
        </a>
      </div>
    <?php endif; ?>
  </section>

  <?php if (is_admin_or_super($user)): ?>
    <!-- Statistik Ringkas Pengurus -->
    <section class="row g-2 mb-3">
      <div class="col-6">
        <div class="aka-card p-3 mb-0 h-100">
          <div class="small text-muted">
            Rumah <?= ($adminRt || $adminRw) ? '(RT ' . e($adminRt ?: '-') . '/RW ' . e($adminRw ?: '-') . ')' : 'Perumahan' ?>
          </div>
          <div class="h4 fw-bold text-success mb-0"><?= $totalRumahWilayah ?></div>
          <a href="<?= e(base_url('admin/blok_rumah.php')) ?>" class="small text-decoration-none">Kelola blok &rarr;</a>
        </div>
      </div>
      <div class="col-6">
        <div class="aka-card p-3 mb-0 h-100">
          <div class="small text-muted">
            Warga <?= ($adminRt || $adminRw) ? '(RT ' . e($adminRt ?: '-') . '/RW ' . e($adminRw ?: '-') . ')' : 'Aktif' ?>
          </div>
          <div class="h4 fw-bold text-success mb-0"><?= $totalWargaWilayah ?></div>
          <div class="text-muted" style="font-size:0.72rem;">Asli AKA: <strong><?= $ktpAsli ?></strong> | Luar: <strong><?= $ktpDomisili ?></strong></div>
        </div>
      </div>
      <div class="col-6">
        <div class="aka-card p-3 mb-0 h-100">
          <div class="small text-muted">Menunggu Verifikasi</div>
          <div class="h4 fw-bold <?= $menungguVerif > 0 ? 'text-warning' : 'text-success' ?> mb-0"><?= $menungguVerif ?></div>
          <a href="<?= e(base_url('admin/pembayaran.php?status=menunggu_verifikasi')) ?>" class="small text-decoration-none">Cek bukti &rarr;</a>
        </div>
      </div>
      <div class="col-6">
        <div class="aka-card p-3 mb-0 h-100">
          <div class="small text-muted">Belum Bayar (Bln Ini)</div>
          <div class="h4 fw-bold text-danger mb-0"><?= $belumBayarBln ?></div>
          <a href="<?= e(base_url('admin/pembayaran.php?status=belum_bayar')) ?>" class="small text-decoration-none">Lihat daftar &rarr;</a>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <!-- Chart Keuangan 6 Bulan Terakhir (Profesional) -->
  <section class="aka-card p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h2 class="h6 fw-bold text-success mb-0"><i class="bi bi-bar-chart-fill me-1"></i> Grafik Kas 6 Bulan Terakhir</h2>
      <a href="<?= e(base_url(is_admin_or_super($user) ? 'admin/laporan.php' : 'warga/kas_transparan.php')) ?>" class="small text-success text-decoration-none fw-semibold">
        Detail &rarr;
      </a>
    </div>
    <div style="height: 210px;">
      <canvas id="kasChart"></canvas>
    </div>
  </section>

  <!-- Card Rekap Jumlah Iuran per RT & Gabungan per RW (Semua Role) -->
  <?php if (!empty($rekapRw)): ?>
    <section class="aka-card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 fw-bold text-success mb-0"><i class="bi bi-pie-chart-fill me-1"></i> Jumlah Iuran per RT &amp; Gabungan RW</h2>
        <span class="badge bg-success-subtle text-success rounded-pill" style="font-size:0.68rem;">Lunas</span>
      </div>
      <div class="d-flex flex-column gap-2">
        <?php foreach ($rekapRw as $rwData): $rwLabel = $rwData['rw']; ?>
          <div class="border rounded-3 p-3 bg-white">
            <div class="d-flex justify-content-between align-items-center pb-2 mb-2 border-bottom">
              <div>
                <span class="badge bg-success text-white rounded-pill px-3">RW <?= e($rwLabel) ?></span>
                <small class="text-muted ms-1">Gabungan RT</small>
              </div>
              <div class="text-end">
                <div class="fw-bold text-success"><?= e(rupiah($rwData['total_rw'])) ?></div>
                <small class="text-muted" style="font-size:0.7rem;">Bln ini: <?= e(rupiah($rwData['bulan_rw'])) ?></small>
              </div>
            </div>
            <div class="row g-2">
              <?php foreach ($rwData['rt_list'] as $rtItem): ?>
                <div class="col-6">
                  <div class="aka-card-soft p-2 px-3">
                    <div class="fw-bold text-success" style="font-size:0.75rem;">RT <?= e($rtItem['rt']) ?> / RW <?= e($rwLabel) ?></div>
                    <div class="fw-bold text-dark small"><?= e(rupiah($rtItem['total_iuran'])) ?></div>
                    <div class="text-muted" style="font-size:0.68rem;">Bln ini: <?= e(rupiah($rtItem['bulan_ini'])) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if (is_admin_or_super($user)): ?>
    <!-- 5 Mutasi Kas Terbaru (Admin) -->
    <section class="aka-card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 fw-bold text-success mb-0"><i class="bi bi-clock-history me-1"></i> 5 Mutasi Kas Terbaru</h2>
        <a href="<?= e(base_url('admin/keuangan.php')) ?>" class="small text-success fw-semibold text-decoration-none">Semua &rarr;</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-aka mb-0">
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>Keterangan</th>
              <th class="text-end">Nominal</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentKas as $rk): ?>
              <tr>
                <td class="text-nowrap"><?= e(tanggal_indo($rk['tanggal'])) ?></td>
                <td>
                  <div class="fw-semibold"><?= e($rk['kategori']) ?></div>
                  <small class="text-muted"><?= e($rk['deskripsi']) ?></small>
                </td>
                <td class="text-end text-nowrap fw-bold <?= $rk['jenis'] === 'pemasukan' ? 'text-success' : 'text-danger' ?>">
                  <?= $rk['jenis'] === 'pemasukan' ? '+' : '-' ?> <?= e(rupiah($rk['nominal'])) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>
  <?php if (!is_admin_or_super($user)): ?>
    <!-- Notifikasi Terbaru (Warga) -->
    <section class="aka-card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 fw-bold text-success mb-0"><i class="bi bi-bell-fill me-1"></i> Notifikasi Terbaru</h2>
        <a href="<?= e(base_url('warga/notifikasi.php')) ?>" class="small text-success text-decoration-none fw-semibold">Semua &rarr;</a>
      </div>
      <?php if (empty($notifsWarga)): ?>
        <p class="small text-muted mb-0">Belum ada notifikasi.</p>
      <?php else: ?>
        <div class="list-group list-group-flush">
          <?php foreach ($notifsWarga as $n): ?>
            <div class="list-group-item px-0 py-2">
              <div class="d-flex justify-content-between align-items-center">
                <strong class="small <?= $n['sudah_dibaca'] ? 'text-dark' : 'text-success' ?>"><?= e($n['judul']) ?></strong>
                <small class="text-muted" style="font-size:0.7rem;"><?= e(tanggal_indo($n['created_at'])) ?></small>
              </div>
              <div class="small text-muted"><?= e($n['pesan']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <!-- Pantauan Blok Wilayah yang Belum Bayar Bulan Ini + Tombol Tegur / Sapa -->
  <section id="belum-bayar-wilayah" class="aka-card p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div>
        <h2 class="h6 fw-bold text-success mb-0"><i class="bi bi-chat-heart-fill me-1"></i> Blok Belum Bayar (<?= e(bulan_indo($bulanIni)) ?>)</h2>
        <small class="text-muted">
          <?= ($user['role'] !== 'super_admin' && !empty($user['rt'])) ? 'Wilayah RT ' . e($user['rt']) . ' / RW ' . e($user['rw']) : 'Semua Wilayah' ?>
        </small>
      </div>
      <span class="badge bg-danger-subtle text-danger rounded-pill"><?= count($belumBayarWilayah) ?> tagihan</span>
    </div>

    <?php if (empty($belumBayarWilayah)): ?>
      <div class="text-center py-2 small text-success fw-semibold">
        <i class="bi bi-check-circle-fill me-1"></i> Semua blok di wilayah ini sudah melunasi iuran bulan ini!
      </div>
    <?php else: ?>
      <div class="d-flex flex-column gap-2">
        <?php foreach ($belumBayarWilayah as $bb): ?>
          <div class="border rounded-3 p-3 bg-white">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <div class="fw-bold text-success small">
                <?= $bb['nama_blok'] ? 'Blok ' . e($bb['nama_blok'] . '.' . $bb['nomor_rumah']) : 'Unit Warga' ?>
              </div>
              <span class="badge bg-light text-dark border rounded-pill" style="font-size:0.7rem;">RT <?= e($bb['rt']) ?> / RW <?= e($bb['rw']) ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-center gap-2 mt-2">
              <div class="small text-muted lh-sm">
                <div><?= e($bb['nama_iuran']) ?></div>
                <strong class="text-danger"><?= e(rupiah($bb['nominal'])) ?></strong>
              </div>
              <div class="d-flex align-items-center gap-1 flex-shrink-0">
                <form method="post" onsubmit="return confirm('Kirim sapaan pengingat iuran ke blok ini?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="sapa_iuran">
                  <input type="hidden" name="pembayaran_id" value="<?= (int)$bb['pembayaran_id'] ?>">
                  <button type="submit" class="btn btn-sm btn-aka rounded-pill px-3 py-1 text-nowrap" style="font-size:0.75rem;">
                    <i class="bi bi-hand-thumbs-up-fill me-1"></i> Tegur / Sapa
                  </button>
                </form>
                <?php if (is_admin_or_super($user) && !empty($bb['no_hp'])): ?>
                  <a href="<?= e(wa_link($bb['no_hp'], "Halo warga Blok {$bb['nama_blok']}.{$bb['nomor_rumah']}, yuk bersama melunasi {$bb['nama_iuran']} bulan " . bulan_indo((int)$bb['bulan']) . " sebesar " . rupiah($bb['nominal']) . ". Terima kasih.")) ?>"
                     target="_blank" class="btn btn-sm btn-aka-outline rounded-pill px-2 py-1" title="Kirim WA">
                    <i class="bi bi-whatsapp"></i>
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

<?php else: ?>
  <!-- Informasi Umum Publik (Belum Login) -->
  <section class="aka-card p-3">
    <div class="quick-grid">
      <a href="#wilayah" class="quick-item">
        <div class="quick-icon"><i class="bi bi-houses-fill"></i></div>
        <span class="quick-label">Blok & RT/RW</span>
      </a>
      <a href="#pengumuman" class="quick-item">
        <div class="quick-icon"><i class="bi bi-megaphone-fill"></i></div>
        <span class="quick-label">Pengumuman</span>
      </a>
      <a href="#struktur" class="quick-item">
        <div class="quick-icon"><i class="bi bi-diagram-3-fill"></i></div>
        <span class="quick-label">Pengurus</span>
      </a>
      <a href="#lokasi" class="quick-item">
        <div class="quick-icon"><i class="bi bi-map-fill"></i></div>
        <span class="quick-label">Lokasi</span>
      </a>
    </div>
  </section>
<?php endif; ?>

<!-- Card Informasi Wilayah RT/RW (Publik, Tanpa Data Pribadi Warga) -->
<section id="wilayah" class="mb-4">
  <h2 class="h6 fw-bold text-success mb-2">
    <i class="bi bi-houses-fill me-1"></i> Wilayah RT / RW & Detail Blok
  </h2>

  <div class="row g-2 mb-3">
    <?php foreach ($wilayahPublik as $wp): ?>
      <?php
        $wKey      = $wp['rt'] . '-' . $wp['rw'];
        $blokItems = $blokByWilayah[$wKey] ?? [];
      ?>
      <div class="col-6 col-md-4">
        <div class="aka-card p-3 text-center h-100 mb-0 d-flex flex-column justify-content-between">
          <div>
            <div class="mx-auto mb-2 rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width:46px;height:46px;">
              <i class="bi bi-houses-fill fs-5"></i>
            </div>
            <div class="fw-bold text-success small">RT <?= e($wp['rt']) ?> / RW <?= e($wp['rw']) ?></div>
            <div class="text-muted mb-1" style="font-size:0.72rem;">
              <?= e($wp['nama_rt'] ?: 'Pengurus RT') ?>
            </div>
            <span class="badge bg-success-subtle text-success rounded-pill mb-2" style="font-size:0.68rem;">
              <?= (int)$wp['jml_unit'] ?> Unit
            </span>
          </div>
          <div class="d-flex flex-column gap-1 mt-1">
            <button type="button" class="btn btn-sm btn-aka-outline rounded-pill py-1 px-2" style="font-size:0.72rem;"
              onclick='openDetailBlokModal("RT <?= e($wp["rt"]) ?> / RW <?= e($wp["rw"]) ?>", <?= json_encode($wp["nama_rt"] ?: "Belum ditetapkan") ?>, <?= json_encode($blokItems, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
              <i class="bi bi-eye me-1"></i> Detail Blok
            </button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- Modal Detail Blok per Wilayah RT/RW (Tanpa Data Pribadi) -->
<div class="modal fade" id="detailBlokModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h3 class="modal-title" id="dbModalTitle">Detail Blok Wilayah</h3>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="aka-card-soft p-2 px-3 mb-3 small">
          <strong class="text-success">Pengurus RT:</strong> <span id="dbModalRtNama"></span>
        </div>
        <div id="dbModalList" class="row g-2"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-aka px-4" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<script>
function openDetailBlokModal(title, namaRt, items) {
  document.getElementById('dbModalTitle').textContent = 'Detail Blok - ' + title;
  document.getElementById('dbModalRtNama').textContent = namaRt;
  const wrap = document.getElementById('dbModalList');
  wrap.innerHTML = '';
  if (!items || items.length === 0) {
    wrap.innerHTML = '<div class="col-12 text-center text-muted small py-3">Belum ada unit blok terdaftar di wilayah ini.</div>';
  } else {
    items.forEach(item => {
      const col = document.createElement('div');
      col.className = 'col-6';
      col.innerHTML = `
        <div class="border rounded-3 p-2 d-flex justify-content-between align-items-center bg-light-subtle">
          <span class="fw-bold text-success small">Blok ${item.nama_blok}.${item.nomor_rumah}</span>
          <span class="badge bg-success-subtle text-success rounded-pill" style="font-size:0.68rem;">${item.status_hunian}</span>
        </div>
      `;
      wrap.appendChild(col);
    });
  }
  bootstrap.Modal.getOrCreateInstance(document.getElementById('detailBlokModal')).show();
}
</script>

<!-- Pengumuman Perumahan -->
<section id="pengumuman" class="mb-4">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h2 class="h6 fw-bold text-success mb-0">
      <i class="bi bi-megaphone-fill me-1"></i> Pengumuman & Informasi AKA
    </h2>
  </div>

  <?php if (empty($pengumumanList)): ?>
    <div class="aka-card p-3 text-center text-muted small">Belum ada pengumuman.</div>
  <?php else: ?>
    <div class="d-flex flex-column gap-2">
      <?php foreach ($pengumumanList as $p): ?>
        <?php
          $katBadge = match ($p['kategori']) {
              'darurat'  => 'bg-danger text-white',
              'kegiatan' => 'bg-success-subtle text-success',
              default    => 'bg-light text-success border border-success-subtle',
          };
        ?>
        <div class="aka-card p-3 mb-0">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
            <span class="badge <?= $katBadge ?> rounded-pill text-uppercase" style="font-size:0.65rem;">
              <?= e($p['kategori']) ?>
            </span>
            <small class="text-muted" style="font-size:0.75rem;">
              <?php if ($p['is_pinned']): ?><i class="bi bi-pin-angle-fill text-success me-1"></i><?php endif; ?>
              <?= e(tanggal_indo($p['created_at'])) ?>
            </small>
          </div>
          <h3 class="h6 fw-bold mb-1"><?= e($p['judul']) ?></h3>
          <p class="small text-secondary mb-0" style="white-space: pre-line;"><?= e($p['isi']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- Struktur Organisasi -->
<section id="struktur" class="mb-4">
  <h2 class="h6 fw-bold text-success mb-2">
    <i class="bi bi-people-fill me-1"></i> Struktur Pengurus RT / Paguyuban
  </h2>
  <?php if (empty($strukturList)): ?>
    <div class="aka-card p-3 text-center text-muted small">Data struktur organisasi belum diatur.</div>
  <?php else: ?>
    <div class="row g-2">
      <?php foreach ($strukturList as $s): ?>
        <div class="col-6 col-md-4">
          <div class="aka-card p-3 text-center h-100 mb-0">
            <div class="mx-auto mb-2 rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width:48px;height:48px;">
              <?php if (!empty($s['foto']) && file_exists(APP_ROOT . '/' . $s['foto'])): ?>
                <img src="<?= e(base_url($s['foto'])) ?>" alt="<?= e($s['nama']) ?>" class="rounded-circle w-100 h-100 object-fit-cover">
              <?php else: ?>
                <i class="bi bi-person-badge fs-4"></i>
              <?php endif; ?>
            </div>
            <div class="fw-bold small"><?= e($s['nama']) ?></div>
            <div class="text-success fw-semibold" style="font-size:0.72rem;"><?= e($s['jabatan']) ?></div>
            <?php if (!empty($s['rt']) || !empty($s['rw'])): ?>
              <div><span class="badge bg-success-subtle text-success rounded-pill mt-1" style="font-size:0.65rem;">RT <?= e($s['rt'] ?: '-') ?> / RW <?= e($s['rw'] ?: '-') ?></span></div>
            <?php endif; ?>
            <?php if (!empty($s['no_hp'])): ?>
              <a href="<?= e(wa_link($s['no_hp'])) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-aka-outline mt-2 py-0 px-2" style="font-size:0.7rem;">
                <i class="bi bi-whatsapp me-1"></i> Hubungi
              </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- Card & Grafik Line Kunjungan Website Hari Ini -->
<section class="aka-card p-3 mb-4">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div>
      <h2 class="h6 fw-bold text-success mb-0"><i class="bi bi-graph-up-arrow me-1"></i> Kunjungan Website</h2>
      <small class="text-muted">7 hari terakhir</small>
    </div>
    <div class="text-end">
      <span class="badge bg-success text-white rounded-pill px-3 py-1">Hari Ini: <?= $kunjunganHariIni ?></span>
      <div class="text-muted mt-1" style="font-size:0.68rem;">Unik: <?= $pengunjungHariIni ?> &bull; Total: <?= $totalKunjungan ?></div>
    </div>
  </div>
  <div style="height: 150px;">
    <canvas id="visitChart"></canvas>
  </div>
</section>

<!-- Lokasi Perumahan -->
<section id="lokasi" class="mb-3">
  <div class="aka-card p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h2 class="h6 fw-bold text-success mb-0">
        <i class="bi bi-geo-alt-fill me-1"></i> Lokasi Perumahan
      </h2>
      <?php if ($mapsLinkUrl !== ''): ?>
        <a href="<?= e($mapsLinkUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-aka-outline py-1 px-2" style="font-size:0.75rem;">
          <i class="bi bi-box-arrow-up-right me-1"></i> Buka Maps
        </a>
      <?php endif; ?>
    </div>
    <p class="small text-muted mb-2"><?= e($alamatPerumahan) ?></p>
    <?php if ($mapsEmbedUrl !== ''): ?>
      <div class="ratio ratio-16x9 rounded-3 overflow-hidden border">
        <iframe src="<?= e($mapsEmbedUrl) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
      </div>
    <?php endif; ?>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const ctx = document.getElementById('kasChart');
  if (ctx) {
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [
          {
            label: 'Pemasukan',
            data: <?= json_encode($chartMasuk) ?>,
            backgroundColor: '#05682B',
            borderRadius: 6,
            barPercentage: 0.7
          },
          {
            label: 'Pengeluaran',
            data: <?= json_encode($chartKeluar) ?>,
            backgroundColor: '#E11D48',
            borderRadius: 6,
            barPercentage: 0.7
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              font: { size: 10 },
              callback: (v) => 'Rp ' + (v >= 1000000 ? (v / 1000000).toFixed(1) + 'jt' : (v >= 1000 ? (v / 1000) + 'rb' : v))
            }
          },
          x: { ticks: { font: { size: 10 } } }
        }
      }
    });
  }

  const vCtx = document.getElementById('visitChart');
  if (vCtx) {
    new Chart(vCtx, {
      type: 'line',
      data: {
        labels: <?= json_encode($visitLabels) ?>,
        datasets: [{
          label: 'Kunjungan',
          data: <?= json_encode($visitHits) ?>,
          borderColor: '#05682B',
          backgroundColor: 'rgba(5, 104, 43, 0.12)',
          fill: true,
          tension: 0.35,
          pointRadius: 3,
          pointBackgroundColor: '#05682B'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } } },
          x: { ticks: { font: { size: 10 } } }
        }
      }
    });
  }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
