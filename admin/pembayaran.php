<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../helpers/notification.php';
require_once __DIR__ . '/../helpers/upload.php';

$user = require_role(['super_admin', 'admin']);
$pdo  = db();

$adminRt = ($user['role'] === 'admin' && !empty($user['rt'])) ? $user['rt'] : '';
$adminRw = ($user['role'] === 'admin' && !empty($user['rw'])) ? $user['rw'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_bayar_bulanan') {
        $newVal = get_setting('allow_bayar_bulanan', '1') === '1' ? '0' : '1';
        set_setting('allow_bayar_bulanan', $newVal);
        log_activity('Ubah Kebijakan Bayar Bulanan', $newVal === '1' ? 'Diaktifkan' : 'Dinonaktifkan');
        set_flash('success', 'Pembayaran iuran bulanan kini ' . ($newVal === '1' ? 'DIAKTIFKAN' : 'DINONAKTIFKAN') . ' sesuai pengaturan pengurus.');
        redirect('admin/pembayaran.php');
    }

    if ($action === 'generate_bulanan') {
        $res = run_monthly_auto_billing_and_reminder(true);
        log_activity('Generate Tagihan Bulanan', "Tagihan dibuat: {$res['created_bills']}, Notif: {$res['notified_users']}");
        set_flash('success', "Berhasil generate {$res['created_bills']} tagihan baru & mengirim notifikasi ke {$res['notified_users']} warga.");
        redirect('admin/pembayaran.php');
    }

    if ($action === 'verifikasi') {
        $id      = (int)($_POST['id'] ?? 0);
        $status  = $_POST['status'] ?? '';
        $catatan = trim($_POST['catatan'] ?? '');

        if ($id > 0 && in_array($status, ['lunas', 'ditolak'], true)) {
            $stmt = $pdo->prepare('
                SELECT p.*, u.nama_lengkap, j.nama_iuran
                FROM pembayaran p
                JOIN users u ON u.id = p.user_id
                JOIN jenis_iuran j ON j.id = p.jenis_iuran_id
                WHERE p.id = ?
            ');
            $stmt->execute([$id]);
            $trx = $stmt->fetch();

            if ($trx) {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE pembayaran SET status = ?, catatan = ?, verified_by = ?, tanggal_bayar = COALESCE(tanggal_bayar, NOW()) WHERE id = ?')
                    ->execute([$status, $catatan, $user['id'], $id]);

                if ($status === 'lunas') {
                    $cekKas = $pdo->prepare('SELECT id FROM keuangan_kas WHERE pembayaran_id = ? LIMIT 1');
                    $cekKas->execute([$id]);
                    if (!$cekKas->fetch()) {
                        $desk = "{$trx['nama_iuran']} ({$trx['nama_lengkap']}) - " . bulan_indo((int)$trx['bulan']) . " {$trx['tahun']} [{$trx['kode_transaksi']}]";
                        $pdo->prepare('INSERT INTO keuangan_kas (tanggal, jenis, kategori, deskripsi, nominal, pembayaran_id, created_by) VALUES (CURDATE(), "pemasukan", "Iuran Warga", ?, ?, ?, ?)')
                            ->execute([$desk, $trx['nominal'], $id, $user['id']]);
                    }
                    create_notification((int)$trx['user_id'], 'Pembayaran Diterima (Lunas)', "Pembayaran {$trx['nama_iuran']} sebesar " . rupiah($trx['nominal']) . " ({$trx['kode_transaksi']}) telah diverifikasi LUNAS.", 'pembayaran', true);
                } else {
                    create_notification((int)$trx['user_id'], 'Pembayaran Ditolak', "Pembayaran {$trx['kode_transaksi']} ditolak oleh pengurus. Catatan: " . ($catatan ?: 'Mohon cek ulang bukti transfer.'), 'pembayaran', true);
                }
                $pdo->commit();
                log_activity('Verifikasi Pembayaran', "{$trx['kode_transaksi']} -> {$status}");
                set_flash('success', "Transaksi {$trx['kode_transaksi']} diperbarui menjadi " . strtoupper($status) . ".");
            }
        }
        redirect('admin/pembayaran.php');
    }

    if ($action === 'save_transaksi') {
        $id       = (int)($_POST['id'] ?? 0);
        $wargaId  = (int)($_POST['user_id'] ?? 0);
        $jenisId  = (int)($_POST['jenis_iuran_id'] ?? 0);
        $bulan    = (int)($_POST['bulan'] ?? date('n'));
        $tahun    = (int)($_POST['tahun'] ?? date('Y'));
        $nominal  = (float)($_POST['nominal'] ?? 0);
        $metode   = $_POST['metode_bayar'] ?? 'tunai';
        $status   = $_POST['status'] ?? 'lunas';
        $catatan  = trim($_POST['catatan'] ?? '');

        if ($id > 0 && $nominal > 0) {
            $pdo->prepare('UPDATE pembayaran SET jenis_iuran_id = ?, bulan = ?, tahun = ?, nominal = ?, metode_bayar = ?, status = ?, catatan = ? WHERE id = ?')
                ->execute([$jenisId, $bulan, $tahun, $nominal, $metode, $status, $catatan, $id]);
            if ($status === 'lunas') {
                $cekKas = $pdo->prepare('SELECT id FROM keuangan_kas WHERE pembayaran_id = ? LIMIT 1');
                $cekKas->execute([$id]);
                if ($cekKas->fetch()) {
                    $pdo->prepare('UPDATE keuangan_kas SET nominal = ? WHERE pembayaran_id = ?')->execute([$nominal, $id]);
                } else {
                    $infoTrx = $pdo->prepare('SELECT p.kode_transaksi, p.bulan, p.tahun, u.nama_lengkap, j.nama_iuran FROM pembayaran p JOIN users u ON u.id = p.user_id JOIN jenis_iuran j ON j.id = p.jenis_iuran_id WHERE p.id = ?');
                    $infoTrx->execute([$id]);
                    if ($it = $infoTrx->fetch()) {
                        $desk = "{$it['nama_iuran']} ({$it['nama_lengkap']}) - " . bulan_indo((int)$it['bulan']) . " {$it['tahun']} [{$it['kode_transaksi']}]";
                        $pdo->prepare('INSERT INTO keuangan_kas (tanggal, jenis, kategori, deskripsi, nominal, pembayaran_id, created_by) VALUES (CURDATE(), "pemasukan", "Iuran Warga", ?, ?, ?, ?)')
                            ->execute([$desk, $nominal, $id, $user['id']]);
                    }
                }
            }
            log_activity('Edit Pembayaran', "ID #{$id}");
            set_flash('success', 'Data transaksi pembayaran berhasil diperbarui.');
        } elseif ($wargaId > 0 && $jenisId > 0 && $nominal > 0) {
            $blokStmt = $pdo->prepare('SELECT blok_rumah_id FROM warga_profil WHERE user_id = ? LIMIT 1');
            $blokStmt->execute([$wargaId]);
            $blokId = $blokStmt->fetchColumn() ?: null;

            $kode = 'PAY-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $buktiManual = null;
            try {
                $buktiManual = handle_image_upload($_FILES['bukti_bayar'] ?? [], 'bukti');
            } catch (Throwable $e) {
                set_flash('danger', $e->getMessage());
                redirect('admin/pembayaran.php');
            }
            $pdo->beginTransaction();
            $ins = $pdo->prepare('
                INSERT INTO pembayaran (kode_transaksi, user_id, blok_rumah_id, jenis_iuran_id, bulan, tahun, nominal, metode_bayar, bukti_bayar, status, catatan, verified_by, tanggal_bayar)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $ins->execute([
                $kode, $wargaId, $blokId, $jenisId, $bulan, $tahun, $nominal, $metode, $buktiManual, $status, $catatan,
                $status === 'lunas' ? $user['id'] : null,
                $status === 'lunas' ? date('Y-m-d H:i:s') : null,
            ]);
            $payId = (int)$pdo->lastInsertId();

            if ($status === 'lunas') {
                $wNama = $pdo->query("SELECT nama_lengkap FROM users WHERE id = {$wargaId}")->fetchColumn();
                $jNama = $pdo->query("SELECT nama_iuran FROM jenis_iuran WHERE id = {$jenisId}")->fetchColumn();
                $desk  = "{$jNama} ({$wNama}) - " . bulan_indo($bulan) . " {$tahun} [{$kode}]";
                $pdo->prepare('INSERT INTO keuangan_kas (tanggal, jenis, kategori, deskripsi, nominal, pembayaran_id, created_by) VALUES (CURDATE(), "pemasukan", "Iuran Warga", ?, ?, ?, ?)')
                    ->execute([$desk, $nominal, $payId, $user['id']]);
                create_notification($wargaId, 'Pembayaran Diterima', "Pembayaran {$jNama} bulan " . bulan_indo($bulan) . " {$tahun} sebesar " . rupiah($nominal) . " telah dicatat LUNAS ({$kode}).", 'pembayaran', true);
            }
            $pdo->commit();
            log_activity('Input Pembayaran Manual', $kode);
            set_flash('success', "Transaksi {$kode} berhasil disimpan.");
        }
        redirect('admin/pembayaran.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM keuangan_kas WHERE pembayaran_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM pembayaran WHERE id = ?')->execute([$id]);
            $pdo->commit();
            log_activity('Hapus Pembayaran', "ID #{$id}");
            set_flash('success', 'Data transaksi pembayaran berhasil dihapus.');
        }
        redirect('admin/pembayaran.php');
    }
}

$filterStatus = $_GET['status'] ?? '';
$filterBulan  = (int)($_GET['bulan'] ?? date('n'));
$filterTahun  = (int)($_GET['tahun'] ?? date('Y'));
$search       = trim($_GET['q'] ?? '');
$perPage      = 10;
$page         = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($page - 1) * $perPage;

$where  = ' WHERE 1=1';
$params = [];
if ($adminRt !== '') {
    $where .= ' AND COALESCE(b.rt, u.rt) = ?';
    $params[] = $adminRt;
}
if ($adminRw !== '') {
    $where .= ' AND COALESCE(b.rw, u.rw) = ?';
    $params[] = $adminRw;
}
if ($filterStatus !== '') {
    $where .= ' AND p.status = ?';
    $params[] = $filterStatus;
}
if ($filterBulan > 0) {
    $where .= ' AND p.bulan = ?';
    $params[] = $filterBulan;
}
if ($filterTahun > 0) {
    $where .= ' AND p.tahun = ?';
    $params[] = $filterTahun;
}
if ($search !== '') {
    $where .= ' AND (u.nama_lengkap LIKE ? OR p.kode_transaksi LIKE ? OR b.nama_blok LIKE ? OR b.nomor_rumah LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like);
}

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM pembayaran p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN blok_rumah b ON b.id = p.blok_rumah_id
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
    SELECT p.*, u.nama_lengkap, u.no_hp, b.nama_blok, b.nomor_rumah, j.nama_iuran
    FROM pembayaran p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN blok_rumah b ON b.id = p.blok_rumah_id
    JOIN jenis_iuran j ON j.id = p.jenis_iuran_id
    {$where}
    ORDER BY FIELD(p.status, 'menunggu_verifikasi', 'belum_bayar', 'ditolak', 'lunas'), p.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

$wargaOptSql = '
    SELECT u.id, u.nama_lengkap, b.nama_blok, b.nomor_rumah
    FROM users u
    LEFT JOIN warga_profil wp ON wp.user_id = u.id
    LEFT JOIN blok_rumah b ON b.id = wp.blok_rumah_id
    WHERE u.role = "warga" AND u.status_aktif = 1
';
$wargaOptParams = [];
if ($adminRt !== '' && $adminRw !== '') {
    $wargaOptSql .= ' AND COALESCE(wp.rt, u.rt) = ? AND COALESCE(wp.rw, u.rw) = ?';
    $wargaOptParams = [$adminRt, $adminRw];
}
$wargaOptSql .= ' ORDER BY b.nama_blok ASC, b.nomor_rumah ASC, u.nama_lengkap ASC';
$wargaOptStmt = $pdo->prepare($wargaOptSql);
$wargaOptStmt->execute($wargaOptParams);
$wargaOptions = $wargaOptStmt->fetchAll();
$iuranOptions = $pdo->query('SELECT * FROM jenis_iuran WHERE aktif = 1 ORDER BY id ASC')->fetchAll();

$pageTitle = 'Tracking Pembayaran';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin_nav.php';
$allowBulanan = get_setting('allow_bayar_bulanan', '1') === '1';
?>

<!-- Kebijakan Pembayaran Bulanan (Dapat Diatur Admin) -->
<div class="aka-card-soft p-3 mb-3 d-flex justify-content-between align-items-center gap-2">
  <div class="small">
    <div class="fw-bold text-success"><i class="bi bi-calendar-check me-1"></i> Status Iuran Bulanan Warga</div>
    <div class="text-muted">
      Saat ini: <strong class="<?= $allowBulanan ? 'text-success' : 'text-danger' ?>"><?= $allowBulanan ? 'AKTIF (Warga boleh bayar bulanan)' : 'NONAKTIF (Ditutup sementara)' ?></strong>
    </div>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="toggle_bayar_bulanan">
    <button type="submit" class="btn btn-sm <?= $allowBulanan ? 'btn-outline-danger' : 'btn-aka' ?> rounded-pill px-3 text-nowrap">
      <?= $allowBulanan ? 'Nonaktifkan' : 'Aktifkan' ?>
    </button>
  </form>
</div>

<div class="aka-card p-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h1 class="h6 fw-bold text-success mb-0"><i class="bi bi-receipt-cutoff me-1"></i> Data Pembayaran & Tagihan</h1>
      <small class="text-muted">
        Total: <?= $totalRows ?> transaksi
        <?= ($adminRt || $adminRw) ? ' &bull; Wilayah RT ' . e($adminRt ?: '-') . ' / RW ' . e($adminRw ?: '-') : '' ?>
      </small>
    </div>
    <div class="d-flex gap-2">
      <form method="post" onsubmit="return confirm('Generate tagihan bulan ini untuk semua warga & kirim notifikasi otomatis?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate_bulanan">
        <button type="submit" class="btn btn-sm btn-aka-outline rounded-pill px-3">
          <i class="bi bi-bell-fill me-1"></i> Generate Bln Ini
        </button>
      </form>
      <button type="button" class="btn btn-sm btn-aka rounded-pill px-3" onclick="openPayModal()">
        <i class="bi bi-plus-lg me-1"></i> Tambah
      </button>
    </div>
  </div>

  <!-- Pencarian & Filter -->
  <form method="get" class="row g-2 mb-3">
    <div class="col-12 col-md-4">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Cari nama, kode, blok..." value="<?= e($search) ?>">
    </div>
    <div class="col-4 col-md-2">
      <select name="bulan" class="form-select form-select-sm">
        <option value="0">All Bln</option>
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $filterBulan === $m ? 'selected' : '' ?>><?= e(bulan_indo($m)) ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-3 col-md-2">
      <input type="number" name="tahun" class="form-control form-control-sm" value="<?= $filterTahun ?>">
    </div>
    <div class="col-3 col-md-3">
      <select name="status" class="form-select form-select-sm">
        <option value="">All Status</option>
        <option value="menunggu_verifikasi" <?= $filterStatus === 'menunggu_verifikasi' ? 'selected' : '' ?>>Menunggu</option>
        <option value="belum_bayar" <?= $filterStatus === 'belum_bayar' ? 'selected' : '' ?>>Belum Bayar</option>
        <option value="lunas" <?= $filterStatus === 'lunas' ? 'selected' : '' ?>>Lunas</option>
        <option value="ditolak" <?= $filterStatus === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
      </select>
    </div>
    <div class="col-2 col-md-1 d-grid">
      <button type="submit" class="btn btn-sm btn-aka-outline"><i class="bi bi-search"></i></button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-aka mb-0">
      <thead>
        <tr>
          <th>Warga & Kode</th>
          <th>Iuran & Periode</th>
          <th>Status</th>
          <th class="text-end">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($list)): ?>
          <tr><td colspan="4" class="text-center text-muted py-3">Belum ada data transaksi pada filter ini.</td></tr>
        <?php endif; ?>
        <?php foreach ($list as $row): ?>
          <tr>
            <td>
              <div class="fw-bold"><?= e($row['nama_lengkap']) ?>
                <span class="text-success"><?= $row['nama_blok'] ? '(' . e($row['nama_blok'] . '.' . $row['nomor_rumah']) . ')' : '' ?></span>
              </div>
              <small class="text-muted"><?= e($row['kode_transaksi']) ?></small>
            </td>
            <td>
              <div><?= e($row['nama_iuran']) ?></div>
              <div class="fw-semibold text-success"><?= e(rupiah($row['nominal'])) ?> &bull; <small class="text-muted"><?= e(bulan_indo((int)$row['bulan'])) ?> <?= (int)$row['tahun'] ?></small></div>
              <?php if (!empty($row['bukti_bayar'])): ?>
                <a href="<?= e(base_url($row['bukti_bayar'])) ?>" target="_blank" class="badge bg-info-subtle text-info-emphasis text-decoration-none">
                  <i class="bi bi-image me-1"></i> Bukti
                </a>
              <?php endif; ?>
            </td>
            <td><?= badge_pembayaran($row['status']) ?></td>
            <td class="text-end text-nowrap">
              <button type="button"
                class="btn btn-sm <?= $row['status'] === 'menunggu_verifikasi' ? 'btn-success' : 'btn-outline-success' ?> py-0 px-2"
                onclick='openVerifModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                title="Lihat Detail & Bukti Pembayaran">
                <i class="bi bi-eye me-1"></i><?= $row['status'] === 'menunggu_verifikasi' ? 'Cek Bukti' : 'Detail' ?>
              </button>
              <?php if (in_array($row['status'], ['menunggu_verifikasi', 'belum_bayar'], true)): ?>
                <a href="<?= e(wa_link($row['no_hp'], "Halo {$row['nama_lengkap']}, pengingat iuran {$row['nama_iuran']} bulan " . bulan_indo((int)$row['bulan']) . " {$row['tahun']} sebesar " . rupiah($row['nominal']) . ". Terima kasih.")) ?>"
                   target="_blank" class="btn btn-sm btn-outline-success py-0 px-2" title="WA">
                  <i class="bi bi-whatsapp"></i>
                </a>
              <?php endif; ?>
              <button type="button" class="btn btn-sm btn-outline-success py-0 px-2"
                onclick='openPayModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit">
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus transaksi ini?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" title="Hapus"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?= render_pagination($page, $totalPages) ?>
</div>

<!-- Modal Verifikasi Faktual Bukti Transfer / Setor -->
<div class="modal fade" id="verifModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content rounded-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="verifikasi">
      <input type="hidden" name="id" id="v_id" value="0">
      <div class="modal-header">
        <h2 class="h6 modal-title"><i class="bi bi-shield-check me-1"></i> Verifikasi Faktual Pembayaran</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="aka-card-soft p-2 px-3 mb-3 small">
          <div class="fw-bold text-success" id="v_warga"></div>
          <div class="text-muted" id="v_detail"></div>
          <div class="fw-bold text-dark mt-1" id="v_nominal"></div>
        </div>

        <div class="mb-3 text-center">
          <div class="small fw-semibold text-muted mb-1">Bukti Setor / Screenshot Transfer:</div>
          <div id="v_bukti_wrap"></div>
        </div>

        <div>
          <label class="form-label small fw-semibold">Catatan Verifikasi (Opsional / Alasan jika ditolak)</label>
          <input type="text" name="catatan" id="v_catatan" class="form-control form-control-sm" placeholder="Contoh: Mutasi rekening sesuai / bukti buram">
        </div>
      </div>
      <div class="modal-footer justify-content-between" id="v_footer_actions">
        <button type="submit" name="status" value="ditolak" class="btn btn-sm btn-outline-danger" onclick="return confirm('Tolak pembayaran ini?');">
          <i class="bi bi-x-circle me-1"></i> Tolak
        </button>
        <button type="submit" name="status" value="lunas" class="btn btn-sm btn-aka px-4">
          <i class="bi bi-check-circle-fill me-1"></i> Setujui &amp; Lunaskan
        </button>
      </div>
      <div class="modal-footer" id="v_footer_close" style="display:none;">
        <button type="button" class="btn btn-sm btn-aka px-4" data-bs-dismiss="modal">Tutup</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Tambah / Edit Pembayaran -->
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" enctype="multipart/form-data" class="modal-content rounded-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_transaksi">
      <input type="hidden" name="id" id="p_id" value="0">
      <div class="modal-header border-0 pb-1">
        <h2 class="h6 fw-bold text-success modal-title" id="payModalTitle">Input Pembayaran Baru</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row g-2 pt-2">
        <div class="col-12" id="p_warga_wrap">
          <label class="form-label small fw-semibold">Warga</label>
          <select name="user_id" id="p_warga" class="form-select form-select-sm">
            <option value="">-- Pilih Warga --</option>
            <?php foreach ($wargaOptions as $wo): ?>
              <option value="<?= (int)$wo['id'] ?>">
                <?= $wo['nama_blok'] ? '[' . e($wo['nama_blok'] . '.' . $wo['nomor_rumah']) . '] ' : '' ?><?= e($wo['nama_lengkap']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-7">
          <label class="form-label small fw-semibold">Jenis Iuran</label>
          <select name="jenis_iuran_id" id="p_jenis" class="form-select form-select-sm" required>
            <?php foreach ($iuranOptions as $io): ?>
              <option value="<?= (int)$io['id'] ?>"><?= e($io['nama_iuran']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-5">
          <label class="form-label small fw-semibold">Nominal (Rp)</label>
          <input type="number" name="nominal" id="p_nominal" class="form-control form-control-sm" value="100000" required>
        </div>
        <div class="col-4">
          <label class="form-label small fw-semibold">Bulan</label>
          <input type="number" name="bulan" id="p_bulan" min="1" max="12" class="form-control form-control-sm" value="<?= date('n') ?>">
        </div>
        <div class="col-4">
          <label class="form-label small fw-semibold">Tahun</label>
          <input type="number" name="tahun" id="p_tahun" class="form-control form-control-sm" value="<?= date('Y') ?>">
        </div>
        <div class="col-4">
          <label class="form-label small fw-semibold">Metode</label>
          <select name="metode_bayar" id="p_metode" class="form-select form-select-sm">
            <option value="tunai">Tunai</option>
            <option value="transfer">Transfer</option>
            <option value="qris">QRIS</option>
          </select>
        </div>
        <div class="col-5">
          <label class="form-label small fw-semibold">Status</label>
          <select name="status" id="p_status" class="form-select form-select-sm">
            <option value="lunas">Lunas</option>
            <option value="belum_bayar">Belum Bayar</option>
            <option value="menunggu_verifikasi">Menunggu Verifikasi</option>
            <option value="ditolak">Ditolak</option>
          </select>
        </div>
        <div class="col-7">
          <label class="form-label small fw-semibold">Catatan</label>
          <input type="text" name="catatan" id="p_catatan" class="form-control form-control-sm" placeholder="Opsional">
        </div>
        <div class="col-12" id="p_bukti_wrap">
          <label class="form-label small fw-semibold">Foto Bukti Setor / Kwitansi (Opsional)</label>
          <input type="file" name="bukti_bayar" class="form-control form-control-sm" accept="image/*">
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
const baseAppUrl = <?= json_encode(base_url('')) ?>;
function openVerifModal(data) {
  document.getElementById('v_id').value = data.id;
  document.getElementById('v_warga').textContent = data.nama_lengkap + (data.nama_blok ? ' (Blok ' + data.nama_blok + '.' + data.nomor_rumah + ')' : '');
  document.getElementById('v_detail').textContent = data.nama_iuran + ' • ' + data.kode_transaksi + ' • Metode: ' + (data.metode_bayar || '-').toUpperCase();
  document.getElementById('v_nominal').textContent = 'Rp ' + Number(data.nominal).toLocaleString('id-ID');
  document.getElementById('v_catatan').value = data.catatan || '';

  const isPending = (data.status === 'menunggu_verifikasi' || data.status === 'belum_bayar');
  document.getElementById('v_footer_actions').style.display = isPending ? 'flex' : 'none';
  document.getElementById('v_footer_close').style.display = isPending ? 'none' : 'flex';
  document.getElementById('v_catatan').readOnly = !isPending;

  const wrap = document.getElementById('v_bukti_wrap');
  if (data.bukti_bayar) {
    const imgUrl = baseAppUrl + data.bukti_bayar.replace(/^\/+/, '');
    wrap.innerHTML = `<a href="${imgUrl}" target="_blank" title="Klik untuk perbesar"><img src="${imgUrl}" alt="Bukti Transfer" class="img-fluid rounded-3 border" style="max-height:260px;"></a><div class="small text-muted mt-1">Klik gambar untuk melihat ukuran penuh</div>`;
  } else {
    wrap.innerHTML = `<div class="alert alert-warning small py-2 mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i> Belum ada foto bukti setor/transfer yang diunggah.</div>`;
  }
  bootstrap.Modal.getOrCreateInstance(document.getElementById('verifModal')).show();
}
function openPayModal(data = null) {
  document.getElementById('payModalTitle').textContent = data ? 'Edit Transaksi Pembayaran' : 'Input Pembayaran Baru';
  document.getElementById('p_id').value = data ? data.id : 0;
  document.getElementById('p_warga_wrap').style.display = data ? 'none' : 'block';
  document.getElementById('p_bukti_wrap').style.display = data ? 'none' : 'block';
  document.getElementById('p_warga').required = !data;
  if (data) {
    document.getElementById('p_jenis').value = data.jenis_iuran_id;
    document.getElementById('p_nominal').value = parseInt(data.nominal);
    document.getElementById('p_bulan').value = data.bulan;
    document.getElementById('p_tahun').value = data.tahun;
    document.getElementById('p_metode').value = data.metode_bayar || 'tunai';
    document.getElementById('p_status').value = data.status;
    document.getElementById('p_catatan').value = data.catatan || '';
  } else {
    document.getElementById('p_warga').value = '';
    document.getElementById('p_nominal').value = 100000;
    document.getElementById('p_bulan').value = <?= (int)date('n') ?>;
    document.getElementById('p_tahun').value = <?= (int)date('Y') ?>;
    document.getElementById('p_metode').value = 'tunai';
    document.getElementById('p_status').value = 'lunas';
    document.getElementById('p_catatan').value = '';
  }
  bootstrap.Modal.getOrCreateInstance(document.getElementById('payModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
