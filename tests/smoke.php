<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/daywell-test-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$socket) throw new RuntimeException($errstr);
$address = stream_socket_get_name($socket, false); fclose($socket);
require $root . '/vendor/autoload.php';
$keys = \Minishlink\WebPush\VAPID::createVapidKeys();
file_put_contents($tmp . '/push-config.php', '<?php return ' . var_export(array_merge($keys, ['subject'=>'mailto:test@example.com']), true) . ';');
$env = array_merge(getenv(), ['SYMPTOM_DB_PATH' => $tmp . '/test.sqlite', 'DAYWELL_PUSH_CONFIG' => $tmp . '/push-config.php']);
$process = proc_open([PHP_BINARY, '-S', $address, '-t', $root . '/public'], [0 => ['pipe','r'], 1 => ['file',$tmp.'/server.log','a'], 2 => ['file',$tmp.'/server.log','a']], $pipes, $root, $env);
$base = 'http://' . $address . '/';
$cookie = $tmp . '/cookies';
function request(string $path, ?array $post = null): array {
    global $base, $cookie;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_COOKIEJAR=>$cookie, CURLOPT_COOKIEFILE=>$cookie, CURLOPT_TIMEOUT=>5]);
    if ($post !== null) curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch); return [$code, $body];
}
function pushRequest(array $data, string $csrf): array {
    global $base, $cookie;
    $ch = curl_init($base . 'push-api.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_COOKIEJAR=>$cookie, CURLOPT_COOKIEFILE=>$cookie, CURLOPT_TIMEOUT=>5,
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($data), CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$csrf]]);
    $body = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); unset($ch);
    return [$code,json_decode($body,true)];
}
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
try {
    for ($i=0;$i<50;$i++) { usleep(50000); [$code,$html] = request('?page=symptoms'); if ($code === 200) break; }
    check($code === 200, 'Server starts');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m); $csrf = $m[1];
    $post = fn(array $data, string $page = 'log') => request('?page='.$page, array_merge(['csrf'=>$csrf],$data));
    [$code] = $post(['action'=>'save_symptom','name'=>'Headache','notes'=>'<script>alert(1)</script>'], 'symptoms'); check($code===303,'Create symptom');
    [$code,$html] = request('?page=symptoms'); check(str_contains($html,'&lt;script&gt;alert(1)&lt;/script&gt;'),'Escape notes');
    [$code,$html] = $post(['action'=>'save_symptom','name'=>'headache'], 'symptoms'); check(str_contains($html,'already exists'),'Duplicate symptom');
    $db = new PDO('sqlite:'.$tmp.'/test.sqlite');
    $id = $db->query('SELECT id FROM symptoms')->fetchColumn();
    $entry = ['action'=>'save_entry','symptom_id'=>$id,'experienced_on'=>date('Y-m-d'),'strength'=>4,'notes'=>'Morning'];
    [$code] = $post($entry); check($code===303,'Save entry');
    [$code] = $post(array_merge($entry,['strength'=>8,'notes'=>'Evening'])); check($code===303,'Multiple entries per date');
    check((int)$db->query('SELECT COUNT(*) FROM entries')->fetchColumn()===2,'Entries persisted');
    [$code,$html] = request('?page=stats'); check(str_contains($html,'6.0') || str_contains($html,'>6<'),'Correct mean'); check(str_contains($html,'Headache'),'Statistics breakdown');
    foreach ([['strength'=>11],['strength'=>0],['strength'=>'2.5'],['symptom_id'=>9999],['experienced_on'=>'2026-02-30'],['experienced_on'=>'2999-01-01']] as $invalid) {
        [$code,$html] = $post(array_merge($entry,$invalid)); check($code===200 && str_contains($html,'role="alert"'),'Reject invalid input');
    }
    check((int)$db->query('SELECT COUNT(*) FROM entries')->fetchColumn()===2,'Invalid entries not saved');
    [$code,$html] = $post(['action'=>'delete_symptom','id'=>$id], 'symptoms'); check(str_contains($html,'has diary entries'),'Protect used symptoms');
    [$code] = request('?page=log',['action'=>'save_entry']); check($code===403,'CSRF protection');
    $eid = $db->query('SELECT MIN(id) FROM entries')->fetchColumn();
    [$code] = $post(array_merge($entry,['id'=>$eid,'strength'=>2,'notes'=>'Updated'])); check($code===303,'Edit entry');
    check((int)$db->query('SELECT strength FROM entries WHERE id='.$eid)->fetchColumn()===2,'Update persisted');
    [$code,$html] = request('?page=log&date=2000-01-01'); check(str_contains($html,'No entries yet for this date'),'Date filter');
    [$code] = $post(['action'=>'save_symptom','id'=>$id,'name'=>'Head pain','notes'=>'Revised'],'symptoms'); check($code===303,'Edit symptom');
    foreach ($db->query('SELECT id FROM entries')->fetchAll(PDO::FETCH_COLUMN) as $entryId) { [$code] = $post(['action'=>'delete_entry','id'=>$entryId]); check($code===303,'Delete entry'); }
    [$code] = $post(['action'=>'delete_symptom','id'=>$id], 'symptoms'); check($code===303,'Delete unused symptom');
    check((int)$db->query('SELECT COUNT(*) FROM symptoms')->fetchColumn()===0,'Deletion persisted');
    foreach (['home','log','symptoms','stats','reminders'] as $page) { [$code,$html] = request('?page='.$page); check($code===200 && !str_contains($html,'Warning:') && !str_contains($html,'Fatal error:'),'Render '.$page); }
    [$code] = request('push-api.php'); check($code===405,'API requires POST');
    [$code] = pushRequest(['action'=>'status','endpoint'=>'https://web.push.apple.com/test'],'bad'); check($code===403,'Push CSRF');
    $auth = rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=');
    $push = ['action'=>'save','endpoint'=>'https://web.push.apple.com/test','subscription'=>['endpoint'=>'https://web.push.apple.com/test','keys'=>['p256dh'=>$keys['publicKey'],'auth'=>$auth]],'time'=>'20:00','timezone'=>'Europe/London','skip_logged'=>true];
    [$code,$data] = pushRequest($push,$csrf); check($code===200 && isset($data['message']),'Save reminder');
    check((int)$db->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn()===1,'Subscription persisted');
    [$code,$data] = pushRequest(['action'=>'status','endpoint'=>$push['endpoint']],$csrf); check($code===200 && $data['subscription']['enabled'] && $data['subscription']['time']==='20:00','Read settings');
    foreach ([['time'=>'25:00'],['timezone'=>'Invalid/Zone'],['endpoint'=>'https://127.0.0.1/private'],['subscription'=>['endpoint'=>$push['endpoint'],'keys'=>['p256dh'=>'bad','auth'=>'bad']]]] as $invalid) {
        [$code] = pushRequest(array_merge($push,$invalid),$csrf); check($code===400,'Reject invalid push settings');
    }
    $db->exec("UPDATE push_subscriptions SET last_sent_date='2026-10-25'");
    [$code] = pushRequest(array_merge($push,['time'=>'21:00']),$csrf); check($code===200 && $db->query('SELECT last_sent_date FROM push_subscriptions')->fetchColumn()==='2026-10-25','Updating settings preserves daily deduplication');
    $db->exec('UPDATE push_subscriptions SET last_test_at='.time());
    [$code] = pushRequest(['action'=>'test','endpoint'=>$push['endpoint']],$csrf); check($code===429,'Test rate limit');
    [$code] = pushRequest(['action'=>'disable','endpoint'=>$push['endpoint']],$csrf); check($code===200,'Disable reminder');
    [$code,$data] = pushRequest(['action'=>'status','endpoint'=>$push['endpoint']],$csrf); check(!$data['subscription']['enabled'],'Disabled persisted');
    [$code] = pushRequest(['action'=>'test','endpoint'=>$push['endpoint']],$csrf); check($code===400,'Disabled subscription cannot send test');
    $job = proc_open([PHP_BINARY,$root.'/bin/send-reminders.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$jobPipes,$root,$env);
    $jobOutput = stream_get_contents($jobPipes[1]); stream_get_contents($jobPipes[2]);
    check(proc_close($job)===0 && str_contains($jobOutput,'accepted: 0'),'Cron executes with disabled subscription without sending');
    [$code,$manifest] = request('manifest.webmanifest'); check($code===200 && json_decode($manifest,true)['display']==='standalone','Install manifest');
    [$code,$worker] = request('sw.js'); check($code===200 && str_contains($worker,"addEventListener('push'"),'Worker available');
    echo "Passed $checks checks.\n";
} finally {
    proc_terminate($process); proc_close($process);
    foreach (glob($tmp.'/*') as $file) unlink($file);
    rmdir($tmp);
}
