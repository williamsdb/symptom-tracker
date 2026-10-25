<?php
declare(strict_types=1);
require_once __DIR__ . '/push.php';

/** Caller must hold the database-specific cron lock. */
function runDueReminders(DateTimeImmutable $now, callable $sender): array {
    $sent = 0; $failed = 0;
    foreach (all('SELECT * FROM push_subscriptions WHERE enabled = 1') as $row) {
        if (!reminderDue($row, $now)) continue;
        $date = $now->setTimezone(new DateTimeZone($row['timezone']))->format('Y-m-d');
        // Diary dates and skip checks use the selected reminder timezone's calendar date.
        if ($row['skip_logged'] && scalar('SELECT COUNT(*) FROM entries WHERE experienced_on = ?', [$date])) {
            query('UPDATE push_subscriptions SET last_sent_date = ?, last_error = NULL WHERE id = ?', [$date, $row['id']]);
            continue;
        }
        query('UPDATE push_subscriptions SET last_attempt_at = ? WHERE id = ?', [$now->getTimestamp(), $row['id']]);
        try { $result = $sender($row); } catch (Throwable $ex) { $result = 'failed'; }
        if ($result === 'sent') {
            query('UPDATE push_subscriptions SET last_sent_date = ?, last_error = NULL WHERE id = ?', [$date, $row['id']]); $sent++;
        } elseif ($result === 'expired') {
            query('DELETE FROM push_subscriptions WHERE id = ?', [$row['id']]);
        } else {
            query('UPDATE push_subscriptions SET last_error = ? WHERE id = ?', ['Delivery failed; cron will retry after 15 minutes.', $row['id']]); $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}
