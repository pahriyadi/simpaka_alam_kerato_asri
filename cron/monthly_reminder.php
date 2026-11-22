<?php
declare(strict_types=1);

/**
 * Script Cron Job / Task Scheduler untuk Notifikasi & Tagihan Bulanan Otomatis.
 * Dapat dijalankan via CLI: php c:\xampp\htdocs\SIMPAKA\cron\monthly_reminder.php
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/notification.php';

$force = in_array('--force', $argv ?? [], true);
$result = run_monthly_auto_billing_and_reminder($force);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
