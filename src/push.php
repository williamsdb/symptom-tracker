<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';

function pushConfigPath(): string { return getenv('DAYWELL_PUSH_CONFIG') ?: dirname(__DIR__) . '/data/push-config.php'; }
function pushConfig(): ?array {
    $path = pushConfigPath();
    if (!is_file($path) || !is_file(dirname(__DIR__) . '/vendor/autoload.php')) return null;
    if (!is_readable($path)) {
        throw new RuntimeException('PHP cannot read the reminder key file. Give the web-server PHP user read access to data/push-config.php (or your DAYWELL_PUSH_CONFIG file), keeping it outside public/. The setup command creates this file with owner-only permissions.');
    }
    $bufferLevel = ob_get_level();
    ob_start();
    try {
        $config = @require $path;
    } catch (Throwable $ex) {
        // Do not expose parser errors or file contents: this file contains private keys.
        throw new RuntimeException('The reminder key file could not be loaded. Check its PHP syntax and that the web-server PHP user has read access. Keep the existing keys; do not regenerate them.');
    } finally {
        while (ob_get_level() > $bufferLevel) ob_end_clean();
    }
    foreach (['publicKey', 'privateKey', 'subject'] as $key) {
        if (!is_array($config) || !isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
            throw new RuntimeException('The reminder key file must return an array containing publicKey, privateKey, and subject. Restore the existing configuration from backup; do not share its contents or regenerate working keys.');
        }
    }
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
