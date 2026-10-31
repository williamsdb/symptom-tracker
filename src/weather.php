<?php
declare(strict_types=1);
function weatherRequest(string $url): array {
    if (PHP_OS_FAMILY === 'Darwin') {
        // Some macOS/MAMP libcurl builds crash forked PHP workers while accessing
        // system networking settings. OpenSSL streams avoid that native code path.
        $context = stream_context_create([
            'http'=>['method'=>'GET', 'timeout'=>12, 'follow_location'=>0, 'ignore_errors'=>true, 'header'=>"User-Agent: Daywell/1.0\r\nAccept: application/json\r\n"],
            'ssl'=>['verify_peer'=>true, 'verify_peer_name'=>true],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if (!$stream) throw new RuntimeException('Weather connection failed. Check PHP HTTPS stream support and its CA certificate configuration, or try again later.');
        try {
            $meta = stream_get_meta_data($stream);
            $status = 0;
            foreach ($meta['wrapper_data'] ?? [] as $header) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $match)) $status = (int)$match[1];
            }
            $body = stream_get_contents($stream);
        } finally { fclose($stream); }
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>12, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_USERAGENT=>'Daywell/1.0', CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
        $body = curl_exec($ch); $status = curl_getinfo($ch,CURLINFO_HTTP_CODE);
    }
    if ($body === false || $status !== 200) throw new RuntimeException('Weather service unavailable. Try again later, or enter context manually.');
    $data = json_decode($body,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($data) || !empty($data['error'])) throw new RuntimeException('Weather data is unavailable for this town and date.');
    return $data;
}
function weatherDescription(int $code): string {
    return match ($code) {
        0=>'Clear sky', 1=>'Mainly clear', 2=>'Partly cloudy', 3=>'Overcast', 45,48=>'Fog',
        51,53,55=>'Drizzle', 56,57=>'Freezing drizzle', 61,63,65=>'Rain', 66,67=>'Freezing rain',
        71,73,75,77=>'Snow', 80,81,82=>'Rain showers', 85,86=>'Snow showers', 95,96,99=>'Thunderstorm',
        default=>'Weather code ' . $code,
    };
}
function weatherSummary(array $data, string $date): array {
    if (($data['daily']['time'][0] ?? '') !== $date || !isset($data['daily']['weather_code'][0])) throw new RuntimeException('No weather data is available for this date.');
    $values = [];
    foreach (['temperature_2m','pressure_msl'] as $key) {
        $samples = array_filter($data['hourly'][$key] ?? [], fn($v) => is_numeric($v));
        if (count($samples) < 18) throw new RuntimeException('Not enough hourly weather data is available for this date. Try later or enter context manually.');
        $values[$key] = round(array_sum($samples)/count($samples),1);
    }
    return ['weather'=>weatherDescription((int)$data['daily']['weather_code'][0]), 'temperature_c'=>$values['temperature_2m'], 'pressure_hpa'=>$values['pressure_msl'], 'weather_date'=>$date, 'weather_timezone'=>$data['timezone'] ?? 'UTC', 'weather_source'=>'Open-Meteo'];
}
