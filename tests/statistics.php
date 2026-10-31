<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/statistics.php';
require dirname(__DIR__) . '/src/weather.php';
$checks=0;
function check(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
$symptoms=[['id'=>1,'name'=>'Headache'],['id'=>2,'name'=>'Fatigue'],['id'=>3,'name'=>'Never recorded']];
$entries=[];
foreach (['2026-10-01','2026-10-02','2026-10-03'] as $d) $entries[]=['symptom_id'=>1,'experienced_on'=>$d,'strength'=>2];
foreach (['2026-10-08','2026-10-09','2026-10-10','2026-10-11'] as $d) $entries[]=['symptom_id'=>1,'experienced_on'=>$d,'strength'=>6];
foreach (['2026-10-01','2026-10-02','2026-10-03','2026-10-04','2026-10-05','2026-10-08','2026-10-09','2026-10-10'] as $d) $entries[]=['symptom_id'=>2,'experienced_on'=>$d,'strength'=>5];
$entries[]=['symptom_id'=>1,'experienced_on'=>'2026-10-01','strength'=>4];
$s=symptomStatistics($symptoms,$entries,'2026-10-01','2026-10-14','2026-10-15');$a=$s['symptoms'][0];
check($a['days']===7 && $a['entries']===8,'Deduplicate daily occurrences');
check($s['recorded_days']===9,'Recorded-day denominator');
check(abs($a['share']-7/9*100)<0.001,'Frequency share');
check($a['frequency_ready'] && $a['strength_ready'],'Minimum comparison data');
check(abs($a['early_share']-60)<0.001 && abs($a['late_share']-100)<0.001,'Compare recorded-day shares');
check(abs($a['strength_change']-(6-7/3))<0.001,'Use daily strength means');
check($a['longest']===4 && $a['streak_start']==='2026-10-08' && $a['streak_end']==='2026-10-11','Longest streak and dates');
check($a['first']==='2026-10-01' && $a['span']===11 && $a['since']===4,'History span and since');
check($a['recent_streak']===0,'Break recent streak after missing days');
check($s['symptoms'][2]['first']===null && $s['symptoms'][2]['since']===null,'Never recorded is not zero days ago');
$p=$s['pairs'][0];check($p['shared']===6 && $p['union']===9 && $p['ready'],'Same-day pair denominator and threshold');
$short=symptomStatistics($symptoms,$entries,'2026-10-01','2026-10-03','2026-10-15');check(!$short['symptoms'][0]['frequency_ready'] && !$short['symptoms'][0]['strength_ready'],'Short window has no trend');
$empty=symptomStatistics($symptoms,[],'2026-10-01','2026-10-31','2026-10-31');check($empty['recorded_days']===0 && $empty['symptoms'][0]['share']===null && !$empty['pairs'],'Empty history');
$single=symptomStatistics($symptoms,[['symptom_id'=>1,'experienced_on'=>'2026-10-31','strength'=>4]],'2026-10-31','2026-10-31','2026-10-31');check($single['symptoms'][0]['since']===0 && $single['symptoms'][0]['longest']===1 && !$single['symptoms'][0]['frequency_ready'],'Single occurrence');
$odd=symptomStatistics($symptoms,$entries,'2026-10-01','2026-10-15','2026-10-15');check($odd['early_end']==='2026-10-07' && $odd['late_start']==='2026-10-09','Odd period omits middle day');
check(daysBetween('2026-10-24','2026-10-26')===2,'Calendar day gaps across DST');
$recent=symptomStatistics($symptoms,$entries,'2026-10-10','2026-10-11','2026-10-12');check($recent['symptoms'][0]['recent_streak']===4 && $recent['symptoms'][0]['first']==='2026-10-01','History independent of filter');
$data=['daily'=>['time'=>['2026-10-10'],'weather_code'=>[63]],'hourly'=>['temperature_2m'=>array_fill(0,24,12.5),'pressure_msl'=>array_fill(0,24,1013.2)],'timezone'=>'Europe/London'];
$weather=weatherSummary($data,'2026-10-10');check($weather['weather']==='Rain' && $weather['temperature_c']===12.5 && $weather['pressure_hpa']===1013.2,'Weather summary and units');
$data['hourly']['pressure_msl']=array_fill(0,24,null);
try { weatherSummary($data,'2026-10-10'); check(false,'Missing weather must fail'); } catch(RuntimeException $ex){check(true,'Missing weather not treated as zero');}
$rounded=locationCoordinates(['latitude'=>51.454123,'longitude'=>-0.971239]);
check($rounded===['latitude'=>51.45,'longitude'=>-0.97],'Location precision reduced');
foreach ([['latitude'=>91,'longitude'=>0],['latitude'=>0,'longitude'=>181],['latitude'=>'51.45','longitude'=>0],['longitude'=>0],['latitude'=>INF,'longitude'=>0]] as $invalid) {
    try {locationCoordinates($invalid);check(false,'Invalid coordinates must fail');} catch(RuntimeException $ex){check(true,'Invalid coordinates rejected');}
}
$nearby=locationTown(['osm_id'=>123,'osm_type'=>'relation','lat'=>'51.45','lon'=>'-0.97','address'=>['town'=>'Reading','state'=>'England','country'=>'United Kingdom']]);
check($nearby['label']==='Reading, England, United Kingdom' && $nearby['town_id']==='osm:relation:123','Town normalized from reverse lookup');
check($nearby['latitude']===51.45 && $nearby['longitude']===-0.97,'Town coordinates used for weather');
try {locationTown(['address'=>['road'=>'High Street']]);check(false,'No town must fail');} catch(RuntimeException $ex){check(true,'Missing town falls back to manual search');}
echo "Passed $checks statistics/weather checks.\n";
