<?php
declare(strict_types=1);

function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function rupiah(float|int|string|null $angka): string
{
    $val = (float)($angka ?? 0);
    return 'Rp ' . number_format($val, 0, ',', '.');
}

function bulan_indo(int $bulan): string
{
    $nama = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    return $nama[$bulan] ?? '-';
}

function tanggal_indo(?string $dateStr, bool $withTime = false): string
{
    if (empty($dateStr)) {
        return '-';
    }
    $ts = strtotime($dateStr);
    if ($ts === false) {
        return $dateStr;
    }
    $tgl = date('d', $ts) . ' ' . bulan_indo((int)date('n', $ts)) . ' ' . date('Y', $ts);
    if ($withTime) {
        $tgl .= ' ' . date('H:i', $ts);
    }
    return $tgl;
}

function normalize_phone(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
    if (str_starts_with($digits, '62')) {
        $digits = '0' . substr($digits, 2);
    }
    return $digits;
}

function wa_phone(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
    if (str_starts_with($digits, '0')) {
        return '62' . substr($digits, 1);
    }
    if (!str_starts_with($digits, '62') && $digits !== '') {
        return '62' . $digits;
    }
    return $digits;
}

function wa_link(string $phone, string $message = ''): string
{
    $num = wa_phone($phone);
    if ($num === '') {
        return '#';
    }
    return 'https://wa.me/' . $num . ($message !== '' ? '?text=' . rawurlencode($message) : '');
}

function role_label(string $role): string
{
    return match ($role) {
        'super_admin' => 'Super Admin (Pengembang)',
        'admin'       => 'Admin (Pengurus RT/RW)',
        'warga'       => 'Warga AKA',
        default       => ucfirst($role),
    };
}

function status_ktp_label(string $status): string
{
    return match ($status) {
        'penduduk_asli_perumahan' => 'KTP Asli Perumahan',
        'domisili_luar'           => 'Domisili Asal / Luar',
        default                   => $status,
    };
}

function badge_pembayaran(string $status): string
{
    return match ($status) {
        'lunas'               => '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">Lunas</span>',
        'menunggu_verifikasi' => '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill">Menunggu Verifikasi</span>',
        'ditolak'             => '<span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill">Ditolak</span>',
        default               => '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill">Belum Bayar</span>',
    };
}

function render_pagination(int $currentPage, int $totalPages, array $extraParams = []): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $buildUrl = static function (int $page) use ($extraParams): string {
        $params = array_merge($_GET, $extraParams, ['page' => $page]);
        return '?' . http_build_query($params);
    };

    $html = '<nav aria-label="Navigasi halaman" class="mt-3"><ul class="pagination pagination-sm justify-content-center mb-0 flex-wrap gap-1">';

    $prevDisabled = $currentPage <= 1 ? ' disabled' : '';
    $html .= '<li class="page-item' . $prevDisabled . '"><a class="page-link rounded-3" href="' . e($buildUrl(max(1, $currentPage - 1))) . '">&laquo;</a></li>';

    $start = max(1, $currentPage - 2);
    $end   = min($totalPages, $currentPage + 2);

    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link rounded-3" href="' . e($buildUrl(1)) . '">1</a></li>';
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link border-0 bg-transparent">...</span></li>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $currentPage ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link rounded-3" href="' . e($buildUrl($i)) . '">' . $i . '</a></li>';
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link border-0 bg-transparent">...</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link rounded-3" href="' . e($buildUrl($totalPages)) . '">' . $totalPages . '</a></li>';
    }

    $nextDisabled = $currentPage >= $totalPages ? ' disabled' : '';
    $html .= '<li class="page-item' . $nextDisabled . '"><a class="page-link rounded-3" href="' . e($buildUrl(min($totalPages, $currentPage + 1))) . '">&raquo;</a></li>';
    $html .= '</ul></nav>';

    return $html;
}
