<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);

if (empty($data['endpoint'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Endpoint tidak valid']);
    exit;
}

$endpoint = $data['endpoint'];
$p256dh   = $data['keys']['p256dh'] ?? '';
$auth     = $data['keys']['auth'] ?? '';

$pdo = db();
$cek = $pdo->prepare('SELECT id FROM push_subscriptions WHERE user_id = ? AND endpoint = ? LIMIT 1');
$cek->execute([(int)$user['id'], $endpoint]);

if (!$cek->fetch()) {
    $pdo->prepare('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)')
        ->execute([(int)$user['id'], $endpoint, $p256dh, $auth]);
}

echo json_encode(['ok' => true]);
