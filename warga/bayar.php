<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../helpers/upload.php';
require_once __DIR__ . '/../helpers/notification.php';

$user = require_role(['warga', 'admin', 'super_admin']);
$pdo  = db();
$uid  = (int)$user['id'];

$allowBulanan = get_setting('allow_bayar_bulanan', '1') === '1';
$adminWilayah = get_admin_contact_for_wilayah($user['rt'] ?? null, $user['rw'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_bukti') {
        $payId  = (int)($_POST['pembayaran_id'] ?? 0);
        $metode = $_POST['metode_bayar'] ?? 'transfer';

        try {
            $bukti = handle_image_upload($_FILES['bukti_bayar'] ?? [], 'bukti');
            if (!$bukti) {
                throw new RuntimeException('Silakan pilih foto bukti transfer / pembayaran.');
            }
            $stmt = $pdo->prepare('UPDATE pembayaran SET bukti_bayar = ?, metode_bayar = ?, status = "menunggu_verifikasi", tanggal_bayar = NOW() WHERE id = ? AND user_id = ?');
            $stmt->execute([$bukti, $metode, $payId, $uid]);

            $pInfo = $pdo->prepare('SELECT p.kode_transaksi, p.bulan, p.tahun, p.nominal, j.nama_iuran FROM pembayaran p JOIN jenis_iuran j ON j.id = p.jenis_iuran_id WHERE p.id = ?');
            $pInfo->execute([$payId]);
            if ($pi = $pInfo->fetch()) {
                notify_admins_for_payment($user, $pi['kode_transaksi'], $pi['nama_iuran'], (int)$pi['bulan'], (int)$pi['tahun'], (float)$pi['nominal']);
            }

            log_activity('Upload Bukti Bayar', "Pembayaran ID #{$payId}");
            set_flash('success', 'Bukti pembayaran berhasil dikirim & notifikasi telah diteruskan ke Admin RT/RW. Silakan konfirmasi via WA jika diperlukan.');
        } catch (Throwable $e) {
            set_flash('danger', $e->getMessage());
        }
        redirect('warga/bayar.php');
    }

    if ($action === 'bayar_baru') {
        $jenisId = (int)($_POST['jenis_iuran_id'] ?? 0);
        $bulan   = (int)($_POST['bulan'] ?? date('n'));
        $tahun   = (int)($_POST['tahun'] ?? date('Y'));
        $metode  = $_POST['metode_bayar'] ?? 'transfer';

        $jStmt = $pdo->prepare('SELECT * FROM jenis_iuran WHERE id = ? AND aktif = 1');
        $jStmt->execute([$jenisId]);
        $jenis = $jStmt->fetch();

        if ($jenis) {
            if ($jenis['periode'] === 'bulanan' && !$allowBulanan) {
                set_flash('warning', 'Pembayaran iuran bulanan sedang dinonaktifkan oleh Pengurus berdasarkan kesepakatan.');
                redirect('warga/bayar.php');
            }
            try {
                $bukti = handle_image_upload($_FILES['bukti_bayar'] ?? [], 'bukti');
                if (!$bukti) {
                    throw new RuntimeException('Silakan lampirkan foto bukti transfer / QRIS.');
                }
                $kode = 'PAY-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
                $pdo->prepare('
                    INSERT INTO pembayaran (kode_transaksi, user_id, blok_rumah_id, jenis_iuran_id, bulan, tahun, nominal, metode_bayar, bukti_bayar, status, tanggal_bayar)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "menunggu_verifikasi", NOW())
                ')->execute([$kode, $uid, $user['blok_rumah_id'] ?? null, $jenisId, $bulan, $tahun, $jenis['nominal_default'], $metode, $bukti]);

                notify_admins_for_payment($user, $kode, $jenis['nama_iuran'], $bulan, $tahun, (float)$jenis['nominal_default']);

                set_flash('success', "Pembayaran {$kode} berhasil dikirim & notifikasi telah diteruskan ke Admin RT/RW.");
            } catch (Throwable $e) {
                set_flash('danger', $e->getMessage());
            }
        }
        redirect('warga/bayar.php');
    }
}

$tagihanStmt = $pdo->prepare('
    SELECT p.*, j.nama_iuran, j.periode
    FROM pembayaran p
    JOIN jenis_iuran j ON j.id = p.jenis_iuran_id
    WHERE p.user_id = ? AND p.status IN ("belum_bayar", "ditolak", "menunggu_verifikasi")
    ORDER BY p.tahun DESC, p.bulan DESC
');
$tagihanStmt->execute([$uid]);
$tagihanList = $tagihanStmt->fetchAll();

if ($allowBulanan) {
    $iuranOptions = $pdo->query('SELECT * FROM jenis_iuran WHERE aktif = 1 ORDER BY id ASC')->fetchAll();
} else {
    $iuranOptions = $pdo->query("SELECT * FROM jenis_iuran WHERE aktif = 1 AND periode != 'bulanan' ORDER BY id ASC")->fetchAll();
}

$infoRekening = get_setting('info_rekening', 'Hubungi Bendahara RT untuk info rekening.');
$qrisImage    = get_setting('qris_image', '');

$pageTitle = 'Pembayaran Iuran & QRIS';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Kartu Nomor Rekening & QRIS Resmi -->
<div class="aka-card p-3">
  <h1 class="h6 fw-bold text-success mb-2"><i class="bi bi-qr-code-scan me-1"></i> Nomor Rekening & QRIS Resmi AKA</h1>
  <div class="row g-3 align-items-center">
    <div class="col-12 <?= $qrisImage !== '' ? 'col-md-7' : '' ?>">
      <div class="aka-card-soft p-3 h-100">
        <div class="small fw-bold text-success mb-1"><i class="bi bi-bank me-1"></i> Rekening Transfer / E-Wallet</div>
        <div class="small text-dark" id="teksRekening" style="white-space: pre-line;"><?= e($infoRekening) ?></div>
        <button type="button" class="btn btn-sm btn-aka-outline mt-2 py-1 px-2" onclick="copyRekening()">
          <i class="bi bi-clipboard me-1"></i> Salin Info Rekening
        </button>
      </div>
    </div>
    <?php if ($qrisImage !== '' && file_exists(APP_ROOT . '/' . $qrisImage)): ?>
      <div class="col-12 col-md-5 text-center">
        <div class="border rounded-3 p-2 bg-white">
          <img src="<?= e(base_url($qrisImage)) ?>" alt="QRIS Bendahara AKA" class="img-fluid rounded-2" style="max-height: 220px;">
          <div class="small fw-semibold text-success mt-1">Scan QRIS Bendahara AKA</div>
        </div>
      </div>
    <?php else: ?>
      <div class="col-12">
        <div class="small text-muted border rounded-3 p-2 text-center bg-light">
          <i class="bi bi-qr-code me-1"></i> Gambar QRIS belum diunggah oleh Pengurus di menu Settings Web.
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!$allowBulanan): ?>
  <div class="alert alert-warning small rounded-3">
    <i class="bi bi-info-circle-fill me-1"></i>
    <strong>Info Pengurus:</strong> Pembayaran iuran bulanan saat ini sedang <strong>dinonaktifkan</strong> berdasarkan kesepakatan warga.
  </div>
<?php endif; ?>

<!-- Tagihan Aktif -->
<div class="aka-card p-3">
  <h2 class="h6 fw-bold text-success mb-3"><i class="bi bi-receipt me-1"></i> Tagihan & Konfirmasi Pembayaran</h2>
  <?php if (empty($tagihanList)): ?>
    <div class="text-center py-3 text-muted small">
      <i class="bi bi-check-circle-fill text-success fs-3 d-block mb-1"></i>
      Tidak ada tagihan tertunggak saat ini.
    </div>
  <?php else: ?>
    <div class="d-flex flex-column gap-3">
      <?php foreach ($tagihanList as $t): ?>
        <div class="border rounded-3 p-3">
          <div class="d-flex justify-content-between align-items-start mb-1">
            <div>
              <div class="fw-bold"><?= e($t['nama_iuran']) ?></div>
              <small class="text-muted"><?= e(bulan_indo((int)$t['bulan'])) ?> <?= (int)$t['tahun'] ?> &bull; <?= e($t['kode_transaksi']) ?></small>
            </div>
            <?= badge_pembayaran($t['status']) ?>
          </div>
          <div class="h5 fw-bold text-success my-2"><?= e(rupiah($t['nominal'])) ?></div>

          <?php if ($t['status'] === 'ditolak' && !empty($t['catatan'])): ?>
            <div class="alert alert-danger py-1 px-2 small mb-2">Alasan ditolak: <?= e($t['catatan']) ?></div>
          <?php endif; ?>

          <?php if (in_array($t['status'], ['belum_bayar', 'ditolak'], true)): ?>
            <form method="post" enctype="multipart/form-data" class="row g-2 mt-1">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="upload_bukti">
              <input type="hidden" name="pembayaran_id" value="<?= (int)$t['id'] ?>">
              <div class="col-5 col-md-4">
                <select name="metode_bayar" class="form-select form-select-sm">
                  <option value="transfer">Transfer Bank</option>
                  <option value="qris">QRIS / E-Wallet</option>
                  <option value="tunai">Tunai (Foto Kwitansi)</option>
                </select>
              </div>
              <div class="col-7 col-md-5">
                <input type="file" name="bukti_bayar" class="form-control form-control-sm" accept="image/*" required>
              </div>
              <div class="col-12 col-md-3 d-grid">
                <button type="submit" class="btn btn-sm btn-aka">Kirim Bukti</button>
              </div>
            </form>
          <?php else: ?>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-1">
              <small class="text-muted"><i class="bi bi-hourglass-split me-1"></i> Bukti sudah diunggah, menunggu verifikasi.</small>
              <?php if ($adminWilayah && !empty($adminWilayah['no_hp'])): ?>
                <?php
                  $blokTxt = !empty($user['nama_blok']) ? 'Blok ' . $user['nama_blok'] . '.' . $user['nomor_rumah'] : 'Warga AKA';
                  $waKonf  = "Halo {$adminWilayah['nama_lengkap']}, saya {$user['nama_lengkap']} ({$blokTxt}) sudah mengirim bukti bayar {$t['nama_iuran']} bulan " . bulan_indo((int)$t['bulan']) . " {$t['tahun']} sebesar " . rupiah($t['nominal']) . " [{$t['kode_transaksi']}]. Mohon dicek ya. Terima kasih.";
                ?>
                <a href="<?= e(wa_link($adminWilayah['no_hp'], $waKonf)) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-aka rounded-pill px-3 py-1">
                  <i class="bi bi-whatsapp me-1"></i> Konfirmasi via WA
                </a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if (!empty($iuranOptions)): ?>
<!-- Form Bayar Iuran -->
<div class="aka-card p-3">
  <h2 class="h6 fw-bold text-success mb-2"><i class="bi bi-plus-circle me-1"></i> Kirim Pembayaran Baru</h2>
  <form method="post" enctype="multipart/form-data" class="row g-2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bayar_baru">
    <div class="col-12 col-md-5">
      <label class="form-label small fw-semibold">Jenis Iuran</label>
      <select name="jenis_iuran_id" class="form-select form-select-sm" required>
        <?php foreach ($iuranOptions as $io): ?>
          <option value="<?= (int)$io['id'] ?>"><?= e($io['nama_iuran']) ?> - <?= e(rupiah($io['nominal_default'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small fw-semibold">Bulan</label>
      <select name="bulan" class="form-select form-select-sm">
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $m === (int)date('n') ? 'selected' : '' ?>><?= e(bulan_indo($m)) ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small fw-semibold">Tahun</label>
      <input type="number" name="tahun" class="form-control form-control-sm" value="<?= date('Y') ?>">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small fw-semibold">Metode</label>
      <select name="metode_bayar" class="form-select form-select-sm">
        <option value="transfer">Transfer</option>
        <option value="qris">QRIS</option>
      </select>
    </div>
    <div class="col-12 col-md-9">
      <label class="form-label small fw-semibold">Foto Bukti Pembayaran</label>
      <input type="file" name="bukti_bayar" class="form-control form-control-sm" accept="image/*" required>
    </div>
    <div class="col-12 col-md-3 d-flex align-items-end">
      <button type="submit" class="btn btn-sm btn-aka w-100">Kirim Pembayaran</button>
    </div>
  </form>
</div>
<?php endif; ?>

<script>
function copyRekening() {
  const txt = document.getElementById('teksRekening').innerText;
  if (navigator.clipboard) {
    navigator.clipboard.writeText(txt).then(() => alert('Info rekening berhasil disalin!'));
  }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
