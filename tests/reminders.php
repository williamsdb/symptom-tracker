<?php
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/daywell-reminders-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
putenv('SYMPTOM_DB_PATH=' . $tmp . '/test.sqlite');
putenv('DAYWELL_PUSH_CONFIG=' . $tmp . '/push-config.php');
require dirname(__DIR__) . '/src/reminder-job.php';
require dirname(__DIR__) . '/vendor/autoload.php';
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
try {
    $command = [PHP_BINARY, dirname(__DIR__).'/bin/setup-reminders.php', 'mailto:tester@example.com'];
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    check(proc_close($process) === 0, 'Generate keys: '.$err);
    check(pushConfig() !== null, 'Configuration loads');
    $client = pushClient(); check($client instanceof \Minishlink\WebPush\WebPush, 'Build real WebPush client with keys');
    $configBefore = file_get_contents(pushConfigPath());
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    check(proc_close($process) === 1 && file_get_contents(pushConfigPath()) === $configBefore, 'Setup cannot replace existing keys');
    foreach (['https://web.push.apple.com/test','https://fcm.googleapis.com/test','https://updates.push.services.mozilla.com/test','https://wns1.notify.windows.com/test'] as $endpoint) check(pushEndpointValid($endpoint),'Allow real provider');
    foreach (['http://web.push.apple.com/test','https://localhost/test','https://127.0.0.1/test','https://evilpush.apple.com/test','https://web.push.apple.com.evil.test/test','https://fcm.googleapis.com:8443/test','https://user@fcm.googleapis.com/test'] as $endpoint) check(!pushEndpointValid($endpoint),'Reject untrusted endpoint');
    $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
    $auth = rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=');
    $subscription = validatePushSubscription(['endpoint'=>'https://web.push.apple.com/test','keys'=>['p256dh'=>$keys['publicKey'],'auth'=>$auth]]);
    check($subscription['auth'] === $auth, 'Validate subscription keys');
    // Exercise the library encryption and VAPID signing against a local mock, without sending externally.
    $mock = new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(201)]);
    $signed = new \Minishlink\WebPush\WebPush(['VAPID'=>pushConfig()], [], 15, ['handler'=>$mock, 'allow_redirects'=>false]);
    $report = $signed->sendOneNotification(\Minishlink\WebPush\Subscription::create(['contentEncoding'=>'aes128gcm','endpoint'=>$subscription['endpoint'],'keys'=>['p256dh'=>$keys['publicKey'],'auth'=>$auth]]), '{"title":"Daywell"}');
    check($report->isSuccess(), 'Encrypt and sign a push message');
    check(str_contains(strtolower($report->getRequest()->getHeaderLine('Authorization')), 'vapid'), 'VAPID authorization present');
    check($report->getRequest()->getHeaderLine('Content-Encoding') === 'aes128gcm', 'Encrypted payload');
    query('INSERT INTO push_subscriptions (endpoint,p256dh,auth,reminder_time,timezone,skip_logged) VALUES (?,?,?,?,?,?)', [$subscription['endpoint'],$subscription['p256dh'],$auth,'20:00','Europe/London',0]);
    $now = new DateTimeImmutable('2026-10-24T19:00:00Z'); // 20:00 BST
    $sent = 0; $sender = function(array $row) use (&$sent) { $sent++; return 'sent'; };
    $row = one('SELECT * FROM push_subscriptions');
    check(!reminderDue($row,$now->modify('-1 minute')), 'Not before selected time');
    check(reminderDue($row,$now), 'BST reminder time');
    check(!reminderDue($row,new DateTimeImmutable('2026-10-25T19:00:00Z')), 'Clock change uses GMT');
    check(reminderDue($row,new DateTimeImmutable('2026-10-25T20:00:00Z')), 'GMT reminder time');
    runDueReminders($now,$sender); runDueReminders($now->modify('+1 minute'),$sender);
    check($sent === 1, 'No duplicate daily reminder');
    runDueReminders(new DateTimeImmutable('2026-10-25T20:00:00Z'),$sender);
    check($sent === 2, 'Next day reminder');
    query('UPDATE push_subscriptions SET last_sent_date=NULL,last_attempt_at=0,skip_logged=1');
    query("INSERT INTO symptoms(name) VALUES ('Headache')");
    query("INSERT INTO entries(symptom_id,experienced_on,strength) VALUES (1,'2026-10-24',5)");
    runDueReminders($now,$sender);
    check($sent === 2 && scalar('SELECT last_sent_date FROM push_subscriptions') === '2026-10-24','Skip completed day');
    query('UPDATE push_subscriptions SET last_sent_date=NULL,last_attempt_at=0,skip_logged=0');
    $result = runDueReminders($now,fn($row)=>'failed');
    check($result['failed'] === 1 && scalar('SELECT last_sent_date FROM push_subscriptions') === null,'Failure not recorded as delivered');
    runDueReminders($now->modify('+1 minute'),$sender); check($sent === 2,'Retry backoff');
    runDueReminders($now->modify('+15 minutes'),$sender); check($sent === 3,'Retry after 15 minutes');
    query('UPDATE push_subscriptions SET enabled=0,last_sent_date=NULL,last_attempt_at=0');
    runDueReminders($now,$sender); check($sent === 3,'Disabled subscriptions skipped');
    query('UPDATE push_subscriptions SET enabled=1');
    runDueReminders($now,fn($row)=>'expired');
    check((int)scalar('SELECT COUNT(*) FROM push_subscriptions') === 0,'Expired subscriptions removed');
    echo "Passed $checks reminder checks.\n";
} finally {
    foreach (glob($tmp.'/*') as $file) unlink($file);
    rmdir($tmp);
}
