<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    echo json_encode(['items' => []]);
    exit;
}

$uid    = (int)$user['id'];
$lastId = (int)($_GET['last_id'] ?? 0);

$stmt = db()->prepare('
    SELECT n.id, n.judul, n.pesan, n.tipe, n.created_at
    FROM notifikasi n
    WHERE n.id > ?
      AND (
        (n.user_id = ? AND n.is_read = 0)
        OR (n.user_id IS NULL AND NOT EXISTS (
            SELECT 1 FROM notifikasi_baca nb WHERE nb.notifikasi_id = n.id AND nb.user_id = ?
        ))
      )
    ORDER BY n.id ASC
    LIMIT 10
');
$stmt->execute([$lastId, $uid, $uid]);
$items = $stmt->fetchAll();

echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
