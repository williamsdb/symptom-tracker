<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/push.php';
if (!is_file(dirname(__DIR__) . '/vendor/autoload.php')) { fwrite(STDERR, "Run composer install first.\n"); exit(1); }
require dirname(__DIR__) . '/vendor/autoload.php';
$subject = $argv[1] ?? '';
if (!(str_starts_with($subject, 'mailto:') && filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL)) && !(str_starts_with($subject, 'https://') && filter_var($subject, FILTER_VALIDATE_URL))) { fwrite(STDERR, "Usage: php bin/setup-reminders.php mailto:you@example.com\n"); exit(1); }
$path = pushConfigPath();
if (is_file($path)) { fwrite(STDERR, "Configuration already exists. Keep its keys so existing subscriptions continue working.\n"); exit(1); }
$keys = \Minishlink\WebPush\VAPID::createVapidKeys();
$config = ['subject' => $subject, 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']];
umask(0077);
$file = fopen($path, 'x');
if (!$file) { fwrite(STDERR, "Cannot create reminder configuration. Check directory permissions.\n"); exit(1); }
fwrite($file, "<?php\nreturn " . var_export($config, true) . ";\n"); fclose($file);
echo "Reminder keys created outside the public directory. Ensure PHP web and cron users can read this file.\n";
