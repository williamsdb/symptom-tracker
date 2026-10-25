<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';

function pushConfigPath(): string { return getenv('DAYWELL_PUSH_CONFIG') ?: dirname(__DIR__) . '/data/push-config.php'; }
function pushConfig(): ?array {
    $path = pushConfigPath();
    if (!is_file($path) || !is_file(dirname(__DIR__) . '/vendor/autoload.php')) return null;
    $config = require $path;
    if (!is_array($config) || empty($config['publicKey']) || empty($config['privateKey']) || empty($config['subject'])) return null;
    return $config;
}
function pushClient(): \Minishlink\WebPush\WebPush {
    $config = pushConfig();
    if (!$config) throw new RuntimeException('Reminders are not configured on this server yet. Follow the setup steps in the README.');
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    return new \Minishlink\WebPush\WebPush(['VAPID' => $config], ['TTL' => 3600, 'urgency' => 'normal', 'topic' => 'daywell-reminder'], 15, ['allow_redirects' => false]);
}
function pushEndpointValid(string $endpoint): bool {
    if (strlen($endpoint) > 2048 || !filter_var($endpoint, FILTER_VALIDATE_URL)) return false;
    $url = parse_url($endpoint);
    if (($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || (isset($url['port']) && $url['port'] !== 443)) return false;
    $host = strtolower($url['host'] ?? '');
    return $host === 'fcm.googleapis.com' || $host === 'updates.push.services.mozilla.com'
        || $host === 'push.services.mozilla.com' || str_ends_with($host, '.push.apple.com')
        || str_ends_with($host, '.notify.windows.com');
}
function pushDecode(string $value): string|false {
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $value)) return false;
    return base64_decode(strtr($value, '-_', '+/'), true);
}
function validatePushSubscription(array $subscription): array {
    $endpoint = $subscription['endpoint'] ?? null;
    $key = $subscription['keys']['p256dh'] ?? null;
    $auth = $subscription['keys']['auth'] ?? null;
    if (!is_string($endpoint) || !pushEndpointValid($endpoint)) throw new RuntimeException('This browser’s push provider is not supported. Try Safari, Chrome, Edge, or Firefox.');
    if (!is_string($key) || !is_string($auth)) throw new RuntimeException('Invalid push subscription keys.');
    $decodedKey = pushDecode($key); $decodedAuth = pushDecode($auth);
    if ($decodedKey === false || strlen($decodedKey) !== 65 || $decodedKey[0] !== "\x04" || $decodedAuth === false || strlen($decodedAuth) !== 16) throw new RuntimeException('Invalid push subscription keys.');
    return ['endpoint' => $endpoint, 'p256dh' => $key, 'auth' => $auth];
}
function reminderDue(array $row, DateTimeImmutable $now): bool {
    $local = $now->setTimezone(new DateTimeZone($row['timezone']));
    return (bool)$row['enabled'] && $local->format('H:i') >= $row['reminder_time']
        && $row['last_sent_date'] !== $local->format('Y-m-d')
        && (int)$row['last_attempt_at'] <= $now->getTimestamp() - 900;
}
/** Return a safe status, without logging secret endpoint URLs or keys. */
function sendPush(array $row, bool $test = false): string {
    $client = pushClient();
    $subscription = \Minishlink\WebPush\Subscription::create(['contentEncoding' => 'aes128gcm', 'endpoint' => $row['endpoint'], 'keys' => ['p256dh' => $row['p256dh'], 'auth' => $row['auth']]]);
    $payload = json_encode(['title' => 'Daywell', 'body' => $test ? 'Your reminders are ready. This is a test notification.' : 'Time for your daily Daywell check-in.', 'test' => $test], JSON_THROW_ON_ERROR);
    $report = $client->sendOneNotification($subscription, $payload);
    if ($report->isSuccess()) return 'sent';
    if ($report->isSubscriptionExpired()) return 'expired';
    return 'failed';
}
