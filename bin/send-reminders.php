<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/push.php';
try {
    if (!pushConfig()) throw new RuntimeException('Run composer install and bin/setup-reminders.php first.');
    // Use the same database file to coordinate overlapping cron runs without web-accessible locks.
    $db = db();
    $lockPath = $db->query('PRAGMA database_list')->fetch()['file'] . '.reminders.lock';
    $lock = fopen($lockPath, 'c');
    if (!$lock) throw new RuntimeException('Cannot open cron lock.');
    if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);
    require_once dirname(__DIR__) . '/src/reminder-job.php';
    $result = runDueReminders(new DateTimeImmutable('now', new DateTimeZone('UTC')), fn(array $row) => sendPush($row));
    $sent = $result['sent']; $failed = $result['failed'];
    echo "Reminders accepted: $sent; failed: $failed.\n";
    flock($lock, LOCK_UN); fclose($lock);
    exit($failed ? 1 : 0);
} catch (Throwable $ex) { fwrite(STDERR, "Reminder job failed. Check setup, database permissions and server connectivity.\n"); exit(1); }
