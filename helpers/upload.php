<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

function handle_image_upload(array $file, string $subDir = 'bukti'): ?string
{
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Gagal mengunggah file (kode error: ' . $file['error'] . ').');
    }

    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException('Ukuran file maksimal 3 MB.');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Format gambar harus JPG, JPEG, PNG, atau WEBP.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowedMime, true)) {
        throw new RuntimeException('File yang diunggah bukan gambar yang valid.');
    }

    $targetFolder = UPLOAD_DIR . '/' . trim($subDir, '/');
    if (!is_dir($targetFolder)) {
        mkdir($targetFolder, 0775, true);
    }

    $filename = date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $destPath = $targetFolder . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('Gagal menyimpan file unggahan.');
    }

    return 'assets/uploads/' . trim($subDir, '/') . '/' . $filename;
}
