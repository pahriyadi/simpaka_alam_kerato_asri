<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/format.php';

function send_whatsapp_gateway(string $phone, string $message): bool
{
    $url   = trim(get_setting('wa_gateway_url'));
    $token = trim(get_setting('wa_gateway_token'));
    if ($url === '' || $token === '' || $phone === '') {
        return false;
    }

    $target = wa_phone($phone);
    if (!function_exists('curl_init')) {
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Authorization: ' . $token],
        CURLOPT_POSTFIELDS     => [
            'target'  => $target,
            'message' => $message,
        ],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 300;
}

function send_onesignal_push(?int $userId, string $judul, string $pesan): bool
{
    $appId  = trim(get_setting('onesignal_app_id'));
    $apiKey = trim(get_setting('onesignal_api_key'));
    if ($appId === '' || $apiKey === '' || !function_exists('curl_init')) {
        return false;
    }

    $body = [
        'app_id'   => $appId,
        'headings' => ['en' => $judul, 'id' => $judul],
        'contents' => ['en' => $pesan, 'id' => $pesan],
    ];
    if ($userId !== null) {
        $body['include_aliases'] = ['external_id' => [(string)$userId]];
        $body['target_channel']  = 'push';
    } else {
        $body['included_segments'] = ['All'];
    }

    $ch = curl_init('https://api.onesignal.com/notifications');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Key ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 300;
}

function create_notification(?int $userId, string $judul, string $pesan, string $tipe = 'pengumuman', bool $pushWa = false): void
{
    $stmt = db()->prepare('INSERT INTO notifikasi (user_id, judul, pesan, tipe) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $judul, $pesan, $tipe]);

    send_onesignal_push($userId, $judul, $pesan);

    if ($pushWa && $userId !== null) {
        $uStmt = db()->prepare('SELECT no_hp FROM users WHERE id = ? AND status_aktif = 1 LIMIT 1');
        $uStmt->execute([$userId]);
        $row = $uStmt->fetch();
        if ($row && !empty($row['no_hp'])) {
            send_whatsapp_gateway($row['no_hp'], "*{$judul}*\n\n{$pesan}");
        }
    }
}

function broadcast_notification(string $judul, string $pesan, string $tipe = 'pengumuman', bool $pushWa = false): void
{
    create_notification(null, $judul, $pesan, $tipe, false);

    if ($pushWa) {
        $url   = trim(get_setting('wa_gateway_url'));
        $token = trim(get_setting('wa_gateway_token'));
        if ($url !== '' && $token !== '') {
            $users = db()->query("SELECT no_hp FROM users WHERE status_aktif = 1 AND no_hp != ''")->fetchAll();
            foreach ($users as $u) {
                send_whatsapp_gateway($u['no_hp'], "*{$judul}*\n\n{$pesan}");
            }
        }
    }
}

function count_unread_notifications(int $userId): int
{
    $stmt = db()->prepare('
        SELECT COUNT(*) FROM notifikasi n
        WHERE (n.user_id = ? AND n.is_read = 0)
           OR (n.user_id IS NULL AND NOT EXISTS (
               SELECT 1 FROM notifikasi_baca nb WHERE nb.notifikasi_id = n.id AND nb.user_id = ?
           ))
    ');
    $stmt->execute([$userId, $userId]);
    return (int)$stmt->fetchColumn();
}

function get_user_notifications(int $userId, int $limit = 30): array
{
    $stmt = db()->prepare('
        SELECT n.*,
               CASE
                   WHEN n.user_id IS NOT NULL THEN n.is_read
                   ELSE EXISTS(SELECT 1 FROM notifikasi_baca nb WHERE nb.notifikasi_id = n.id AND nb.user_id = ?)
               END AS sudah_dibaca
        FROM notifikasi n
        WHERE n.user_id = ? OR n.user_id IS NULL
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT ?
    ');
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $userId, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function mark_all_notifications_read(int $userId): void
{
    $pdo = db();
    $pdo->prepare('UPDATE notifikasi SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$userId]);
    $pdo->prepare('
        INSERT IGNORE INTO notifikasi_baca (notifikasi_id, user_id)
        SELECT id, ? FROM notifikasi WHERE user_id IS NULL
    ')->execute([$userId]);
}

/**
 * Generate tagihan bulanan otomatis & kirim notifikasi pengingat bulanan
 * sesuai tanggal yang diatur di Settings (auto_notif_day).
 */
function run_monthly_auto_billing_and_reminder(bool $force = false): array
{
    $enabled      = get_setting('auto_notif_enabled', '1') === '1';
    $allowBulanan = get_setting('allow_bayar_bulanan', '1') === '1';
    if (!$force && (!$enabled || !$allowBulanan)) {
        return ['ran' => false, 'reason' => 'Notifikasi atau pembayaran bulanan otomatis dinonaktifkan'];
    }

    $currentYm  = date('Y-m');
    $targetDay  = max(1, min(28, (int)get_setting('auto_notif_day', '1')));
    $todayDay   = (int)date('j');
    $lastRunYm  = get_setting('last_auto_billing_ym', '');

    if (!$force && ($lastRunYm === $currentYm || $todayDay < $targetDay)) {
        return ['ran' => false, 'reason' => 'Belum waktunya atau sudah berjalan bulan ini'];
    }

    $bulan = (int)date('n');
    $tahun = (int)date('Y');
    $pdo   = db();

    $iuranList = $pdo->query("SELECT * FROM jenis_iuran WHERE periode = 'bulanan' AND aktif = 1")->fetchAll();
    $wargaList = $pdo->query("
        SELECT u.id AS user_id, u.nama_lengkap, u.no_hp, wp.blok_rumah_id
        FROM users u
        LEFT JOIN warga_profil wp ON wp.user_id = u.id
        WHERE u.role = 'warga' AND u.status_aktif = 1
    ")->fetchAll();

    $createdBills = 0;
    $notifiedUsers = 0;

    $cekStmt = $pdo->prepare('SELECT id FROM pembayaran WHERE user_id = ? AND jenis_iuran_id = ? AND bulan = ? AND tahun = ? LIMIT 1');
    $insStmt = $pdo->prepare('
        INSERT INTO pembayaran (kode_transaksi, user_id, blok_rumah_id, jenis_iuran_id, bulan, tahun, nominal, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, "belum_bayar")
    ');

    foreach ($wargaList as $w) {
        $totalTagihanWarga = 0.0;
        foreach ($iuranList as $iuran) {
            $cekStmt->execute([$w['user_id'], $iuran['id'], $bulan, $tahun]);
            if (!$cekStmt->fetch()) {
                $kode = 'INV-' . $tahun . sprintf('%02d', $bulan) . '-' . $w['user_id'] . '-' . $iuran['id'] . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
                $insStmt->execute([
                    $kode,
                    $w['user_id'],
                    $w['blok_rumah_id'],
                    $iuran['id'],
                    $bulan,
                    $tahun,
                    $iuran['nominal_default'],
                ]);
                $createdBills++;
                $totalTagihanWarga += (float)$iuran['nominal_default'];
            }
        }

        if ($totalTagihanWarga > 0) {
            $namaBulan = bulan_indo($bulan) . ' ' . $tahun;
            $judul = "Tagihan Iuran Bulan {$namaBulan}";
            $pesan = "Yth. {$w['nama_lengkap']}, tagihan iuran bulanan periode {$namaBulan} sebesar " . rupiah($totalTagihanWarga) . " telah terbit. Silakan lakukan pembayaran melalui menu Pembayaran di SIMPAKA.";
            create_notification((int)$w['user_id'], $judul, $pesan, 'tagihan_bulanan', true);
            $notifiedUsers++;
        }
    }

    set_setting('last_auto_billing_ym', $currentYm);
    return [
        'ran'            => true,
        'created_bills'  => $createdBills,
        'notified_users' => $notifiedUsers,
    ];
}

function get_admin_contact_for_wilayah(?string $rt, ?string $rw): ?array
{
    $pdo = db();
    if (!empty($rt) && !empty($rw)) {
        $stmt = $pdo->prepare("SELECT id, nama_lengkap, no_hp FROM users WHERE role = 'admin' AND status_aktif = 1 AND rt = ? AND rw = ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$rt, $rw]);
        if ($row = $stmt->fetch()) {
            return $row;
        }
        $stmt2 = $pdo->prepare("SELECT nama AS nama_lengkap, no_hp FROM struktur_organisasi WHERE rt = ? AND rw = ? AND no_hp != '' ORDER BY urutan ASC, id ASC LIMIT 1");
        $stmt2->execute([$rt, $rw]);
        if ($row2 = $stmt2->fetch()) {
            return $row2;
        }
    }
    $fallback = $pdo->query("SELECT id, nama_lengkap, no_hp FROM users WHERE role IN ('admin', 'super_admin') AND status_aktif = 1 AND no_hp != '' ORDER BY role DESC, id ASC LIMIT 1")->fetch();
    return $fallback ?: null;
}

function notify_admins_for_payment(array $wargaUser, string $kode, string $namaIuran, int $bulan, int $tahun, float $nominal): void
{
    $pdo = db();
    $rt  = $wargaUser['rt'] ?? '';
    $rw  = $wargaUser['rw'] ?? '';

    $admins = [];
    if ($rt !== '' && $rw !== '') {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND status_aktif = 1 AND rt = ? AND rw = ?");
        $stmt->execute([$rt, $rw]);
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    if (empty($admins)) {
        $admins = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'super_admin') AND status_aktif = 1")->fetchAll(PDO::FETCH_COLUMN);
    }

    $blokTxt = !empty($wargaUser['nama_blok']) ? 'Blok ' . $wargaUser['nama_blok'] . '.' . $wargaUser['nomor_rumah'] : 'Warga';
    $judul   = "Pembayaran Masuk ({$blokTxt})";
    $pesan   = "{$wargaUser['nama_lengkap']} ({$blokTxt}) mengirim bukti pembayaran {$namaIuran} periode " . bulan_indo($bulan) . " {$tahun} sebesar " . rupiah($nominal) . " [{$kode}]. Silakan cek bukti setor & verifikasi.";

    foreach ($admins as $admId) {
        create_notification((int)$admId, $judul, $pesan, 'pembayaran', true);
    }
}
