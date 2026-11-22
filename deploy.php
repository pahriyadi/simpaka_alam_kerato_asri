<?php
/**
 * GITHUB WEBHOOK AUTO-DEPLOY SCRIPT - SIMPAKA (Alam Kerato Asri)
 * Endpoint: https://domain-anda.com/deploy
 * Fungsi  : Menarik update otomatis (git pull origin main) saat ada push ke GitHub.
 */

date_default_timezone_set('Asia/Makassar');
header('Content-Type: application/json; charset=utf-8');

$webhook_secret = 'SIMPAKA_AKA_AutoDeploy_2026!';

$raw_payload   = file_get_contents('php://input');
$signature_256 = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$github_event  = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

$manual_key      = $_GET['key'] ?? '';
$is_valid_manual = ($manual_key !== '' && hash_equals($webhook_secret, $manual_key));

$is_valid_webhook = false;
if ($signature_256 !== '' && $raw_payload !== '') {
    $expected_signature = 'sha256=' . hash_hmac('sha256', $raw_payload, $webhook_secret);
    $is_valid_webhook   = hash_equals($expected_signature, $signature_256);
}

if (!$is_valid_webhook && !$is_valid_manual) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak: Signature atau Key tidak valid.'], JSON_PRETTY_PRINT);
    exit;
}

if ($github_event === 'ping') {
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Pong! Webhook SIMPAKA Alam Kerato Asri terhubung.'], JSON_PRETTY_PRINT);
    exit;
}

if ($is_valid_webhook && $github_event === 'push') {
    $payload_data = json_decode($raw_payload, true);
    $ref = $payload_data['ref'] ?? '';
    if ($ref !== '' && $ref !== 'refs/heads/main') {
        http_response_code(200);
        echo json_encode(['status' => 'ignored', 'message' => "Push ke '{$ref}' diabaikan (khusus refs/heads/main)."], JSON_PRETTY_PRINT);
        exit;
    }
}

chdir(__DIR__);
$output = [];
$code   = 0;

if (function_exists('exec')) {
    @exec('git pull origin main 2>&1', $output, $code);
} else {
    $output[] = 'Fungsi exec() dinonaktifkan di server.';
    $code = 1;
}

echo json_encode([
    'status' => $code === 0 ? 'success' : 'error',
    'code'   => $code,
    'output' => implode("\n", $output),
    'time'   => date('Y-m-d H:i:s'),
], JSON_PRETTY_PRINT);
