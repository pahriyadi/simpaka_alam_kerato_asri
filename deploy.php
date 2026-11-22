<?php
/**
 * =========================================================================
 * GITHUB WEBHOOK AUTO-DEPLOY SCRIPT - SIMPAKA (Alam Kerato Asri)
 * =========================================================================
 * Domain    : https://simpaka.my.id
 * Endpoint  : https://simpaka.my.id/deploy
 * Path cPanel: /home/n1579664/public_html/simpaka.my.id
 * Fungsi    : Menarik update otomatis (git pull origin main) setiap kali
 *             ada git push ke branch main di GitHub tanpa buka terminal cPanel.
 * =========================================================================
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
    echo json_encode([
        'status'  => 'error',
        'message' => 'Akses ditolak: Signature GitHub Webhook atau Key tidak valid.'
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($github_event === 'ping') {
    http_response_code(200);
    echo json_encode([
        'status'  => 'success',
        'message' => 'Pong! Webhook SIMPAKA (simpaka.my.id) berhasil terhubung.'
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($is_valid_webhook && $github_event === 'push') {
    $payload_data = json_decode($raw_payload, true);
    $ref = $payload_data['ref'] ?? '';
    if ($ref !== '' && $ref !== 'refs/heads/main') {
        http_response_code(200);
        echo json_encode([
            'status'  => 'ignored',
            'message' => "Push ke branch '{$ref}' diabaikan (hanya memproses refs/heads/main)."
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

$repo_dir = __DIR__;
$log_dir  = $repo_dir . '/logs';
$log_file = $log_dir . '/deploy.log';

chdir($repo_dir);

function run_git_command(string $cmd): array
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    $full_cmd = $cmd . ' 2>&1';

    if (function_exists('proc_open') && !in_array('proc_open', $disabled, true)) {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($cmd, $descriptors, $pipes, __DIR__);
        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            return ['output' => trim($stdout . "\n" . $stderr), 'code' => $code, 'method' => 'proc_open'];
        }
    }

    if (function_exists('exec') && !in_array('exec', $disabled, true)) {
        $output = [];
        $return_var = 0;
        @exec($full_cmd, $output, $return_var);
        return ['output' => implode("\n", $output), 'code' => $return_var, 'method' => 'exec'];
    }

    if (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
        $out = @shell_exec($full_cmd);
        return ['output' => trim((string)$out), 'code' => 0, 'method' => 'shell_exec'];
    }

    if (function_exists('system') && !in_array('system', $disabled, true)) {
        ob_start();
        $return_var = 0;
        @system($full_cmd, $return_var);
        $out = ob_get_clean();
        return ['output' => trim((string)$out), 'code' => $return_var, 'method' => 'system'];
    }

    if (function_exists('popen') && !in_array('popen', $disabled, true)) {
        $handle = @popen($full_cmd, 'r');
        if ($handle) {
            $out = '';
            while (!feof($handle)) {
                $out .= fread($handle, 2096);
            }
            pclose($handle);
            return ['output' => trim($out), 'code' => 0, 'method' => 'popen'];
        }
    }

    return ['output' => 'Semua fungsi eksekusi shell dinonaktifkan oleh server.', 'code' => 1, 'method' => 'none'];
}

$git_bin = 'git';
if (file_exists('/usr/local/cpanel/3rdparty/bin/git')) {
    $git_bin = '/usr/local/cpanel/3rdparty/bin/git';
} elseif (file_exists('/usr/bin/git')) {
    $git_bin = '/usr/bin/git';
}

$cmd        = "{$git_bin} fetch origin main && {$git_bin} reset --hard origin/main && {$git_bin} pull origin main";
$run_result = run_git_command($cmd);

if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0775, true);
}
if (is_dir($log_dir) && is_writable($log_dir)) {
    $log_entry = sprintf(
        "[%s WITA] Event: %s | Method: %s | Code: %d\nOutput:\n%s\n%s\n",
        date('Y-m-d H:i:s'),
        ($is_valid_manual ? 'manual_trigger' : ($github_event ?: 'webhook')),
        $run_result['method'],
        $run_result['code'],
        $run_result['output'],
        str_repeat('-', 60)
    );
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
}

http_response_code($run_result['code'] === 0 ? 200 : 500);
echo json_encode([
    'status'    => ($run_result['code'] === 0 ? 'success' : 'error'),
    'timestamp' => date('Y-m-d H:i:s') . ' WITA',
    'method'    => $run_result['method'],
    'git_bin'   => $git_bin,
    'output'    => $run_result['output']
], JSON_PRETTY_PRINT);
