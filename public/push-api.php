<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/push.php';
header('Content-Type: application/json; charset=utf-8');
function respond(array $data, int $status = 200): never { http_response_code($status); echo json_encode($data, JSON_THROW_ON_ERROR); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); respond(['error' => 'POST required.'], 405); }
if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) respond(['error' => 'Your session expired. Reload this page and try again.'], 403);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 12000) respond(['error' => 'Request too large.'], 413);
try {
    $input = json_decode(file_get_contents('php://input', false, null, 0, 12001), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new RuntimeException('Invalid request.');
    $action = $input['action'] ?? '';
    if (!in_array($action, ['status', 'save', 'disable', 'test'], true)) throw new RuntimeException('Unknown action.');
    $endpoint = $input['endpoint'] ?? '';
    if (!is_string($endpoint) || !pushEndpointValid($endpoint)) throw new RuntimeException('Invalid push endpoint.');
    $row = one('SELECT * FROM push_subscriptions WHERE endpoint = ?', [$endpoint]);
    if ($action === 'status') respond(['subscription' => $row ? ['enabled' => (bool)$row['enabled'], 'time' => $row['reminder_time'], 'timezone' => $row['timezone'], 'skip_logged' => (bool)$row['skip_logged'], 'last_error' => $row['last_error']] : null]);
    if ($action === 'disable') {
        query('UPDATE push_subscriptions SET enabled = 0 WHERE endpoint = ?', [$endpoint]);
        respond(['message' => 'Reminders are off for this device.']);
    }
    if (!pushConfig()) respond(['error' => 'The server needs reminder setup. See the README.'], 503);
    if ($action === 'save') {
        $subscription = validatePushSubscription($input['subscription'] ?? []);
        if ($subscription['endpoint'] !== $endpoint) throw new RuntimeException('Subscription mismatch.');
        $time = $input['time'] ?? ''; $timezone = $input['timezone'] ?? '';
        if (!is_string($time) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) throw new RuntimeException('Choose a valid reminder time.');
        if (!is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) throw new RuntimeException('Choose a valid timezone.');
        query('INSERT INTO push_subscriptions (endpoint, p256dh, auth, reminder_time, timezone, skip_logged) VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(endpoint) DO UPDATE SET p256dh=excluded.p256dh, auth=excluded.auth, reminder_time=excluded.reminder_time,
            timezone=excluded.timezone, skip_logged=excluded.skip_logged, enabled=1, last_error=NULL',
            [$endpoint, $subscription['p256dh'], $subscription['auth'], $time, $timezone, !empty($input['skip_logged']) ? 1 : 0]);
        respond(['message' => 'Daily reminders are enabled for this device.']);
    }
    if (!$row || !$row['enabled']) throw new RuntimeException('Enable reminders on this device first.');
    $claim = query('UPDATE push_subscriptions SET last_test_at = ? WHERE id = ? AND last_test_at <= ?', [time(), $row['id'], time() - 60]);
    if (!$claim->rowCount()) respond(['error' => 'Please wait one minute between test notifications.'], 429);
    session_write_close();
    $result = sendPush($row, true);
    if ($result === 'expired') {
        query('DELETE FROM push_subscriptions WHERE id = ?', [$row['id']]);
        respond(['error' => 'This subscription expired. Disable reminders, then enable them again.'], 410);
    }
    if ($result !== 'sent') respond(['error' => 'The push service did not accept the test. Check server keys and outbound HTTPS access.'], 502);
    respond(['message' => 'Test accepted by the push service. Check your device notifications.']);
} catch (JsonException | TypeError $ex) {
    respond(['error' => 'Invalid request.'], 400);
} catch (PDOException $ex) {
    respond(['error' => 'Could not save reminder settings. Check database permissions.'], 503);
} catch (RuntimeException $ex) {
    respond(['error' => $ex->getMessage()], 400);
} catch (Throwable $ex) {
    respond(['error' => 'Unable to send the notification. Check the server setup and outbound HTTPS access.'], 502);
}
