<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/weather.php';
header('Content-Type: application/json; charset=utf-8');
function weatherRespond(array $data, int $status = 200): never { http_response_code($status); echo json_encode($data,JSON_THROW_ON_ERROR); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); weatherRespond(['error'=>'POST required.'],405); }
if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) weatherRespond(['error'=>'Session expired. Reload the page.'],403);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>2000) weatherRespond(['error'=>'Request too large.'],413);
try {
    $input = json_decode(file_get_contents('php://input',false,null,0,2001),true,8,JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new RuntimeException('Invalid request.');
    $savedTown = one('SELECT * FROM weather_preferences WHERE id = 1');
    $townId = (string)($input['town_id'] ?? '');
    $town = $_SESSION['weather_towns'][$townId] ?? null;
    if ($town && $town['expires'] < time()) $town = null;
    if (!$town && $savedTown && $savedTown['town_id'] === $townId) $town = $savedTown;
    if (($input['action'] ?? '') === 'choose') {
        if (!$town) throw new RuntimeException('Search for and select a town again.');
        query('INSERT INTO weather_preferences (id, town_id, label, latitude, longitude) VALUES (1, ?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET town_id=excluded.town_id, label=excluded.label, latitude=excluded.latitude, longitude=excluded.longitude',
            [$townId, $town['label'], $town['latitude'], $town['longitude']]);
        weatherRespond(['town'=>$town['label']]);
    }
    if (time() - ($_SESSION['weather_request_at'] ?? 0) < 2) weatherRespond(['error'=>'Please wait a moment before another lookup.'],429);
    $_SESSION['weather_request_at'] = time();
    if (($input['action'] ?? '') === 'search') {
        $name = trim((string)($input['town'] ?? ''));
        if (mb_strlen($name)<2 || mb_strlen($name)>100) throw new RuntimeException('Enter a town name of 2–100 characters.');
        $data = weatherRequest('https://geocoding-api.open-meteo.com/v1/search?' . http_build_query(['name'=>$name,'count'=>5,'language'=>'en','format'=>'json']));
        $results = [];
        foreach ($data['results'] ?? [] as $town) {
            if (!isset($town['id'],$town['latitude'],$town['longitude'],$town['name'])) continue;
            $id = (string)$town['id'];
            $label = implode(', ', array_unique(array_filter([$town['name'],$town['admin1'] ?? '',$town['country'] ?? ''])));
            $_SESSION['weather_towns'][$id] = ['latitude'=>$town['latitude'],'longitude'=>$town['longitude'],'label'=>$label,'expires'=>time()+1800];
            $results[] = ['id'=>$id,'label'=>$label];
        }
        $_SESSION['weather_towns'] = array_slice(array_filter($_SESSION['weather_towns'] ?? [],fn($t)=>$t['expires']>=time()),-25,null,true);
        weatherRespond(['towns'=>$results]);
    }
    if (($input['action'] ?? '') !== 'weather') throw new RuntimeException('Unknown action.');
    if (!$town) throw new RuntimeException('Search for and select a town again.');
    $date = (string)($input['date'] ?? '');
    if (!validDate($date) || $date < '1940-01-01' || $date > date('Y-m-d')) throw new RuntimeException('Weather lookup needs a date from 1940 through today.');
    session_write_close();
    $recent = $date >= date('Y-m-d',strtotime('-5 days'));
    $url = $recent ? 'https://api.open-meteo.com/v1/forecast?' : 'https://archive-api.open-meteo.com/v1/archive?';
    $data = weatherRequest($url . http_build_query(['latitude'=>$town['latitude'],'longitude'=>$town['longitude'],'start_date'=>$date,'end_date'=>$date,'hourly'=>'temperature_2m,pressure_msl','daily'=>'weather_code','timezone'=>'auto']));
    weatherRespond(array_merge(weatherSummary($data,$date),['town'=>$town['label'],'weather_source'=>$recent ? 'Open-Meteo forecast' : 'Open-Meteo historical']));
} catch (RuntimeException $ex) { weatherRespond(['error'=>$ex->getMessage()],400); }
catch (Throwable $ex) { weatherRespond(['error'=>'Unable to look up weather. Try later or enter context manually.'],502); }
