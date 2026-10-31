<?php
declare(strict_types=1);

/** Calendar-day differences, independent of daylight saving changes. */
function daysBetween(string $a, string $b): int {
    return (int)(new DateTimeImmutable($a))->diff(new DateTimeImmutable($b))->format('%r%a');
}
/** Multiple records of one symptom on one date count as one occurrence day. */
function symptomStatistics(array $symptoms, array $entries, string $from, string $to, string $today): array {
    $daily = []; $recorded = []; $rows = [];
    foreach ($entries as $entry) {
        $id = (int)$entry['symptom_id']; $date = $entry['experienced_on'];
        if ($date > $today) continue;
        $daily[$id][$date][] = (int)$entry['strength'];
        if ($date >= $from && $date <= $to) $recorded[$date] = true;
    }
    $length = daysBetween($from, $to) + 1;
    $half = intdiv($length, 2);
    $earlyEnd = (new DateTimeImmutable($from))->modify('+' . max(0, $half - 1) . ' days')->format('Y-m-d');
    $lateStart = (new DateTimeImmutable($to))->modify('-' . max(0, $half - 1) . ' days')->format('Y-m-d');
    $earlyRecorded = count(array_filter(array_keys($recorded), fn($d) => $d <= $earlyEnd));
    $lateRecorded = count(array_filter(array_keys($recorded), fn($d) => $d >= $lateStart));
    foreach ($symptoms as $symptom) {
        $dates = $daily[$symptom['id']] ?? []; ksort($dates);
        $selected = array_filter($dates, fn($date) => $date >= $from && $date <= $to, ARRAY_FILTER_USE_KEY);
        $means = array_map(fn($values) => array_sum($values) / count($values), $selected);
        $early = array_filter($means, fn($d) => $d <= $earlyEnd, ARRAY_FILTER_USE_KEY);
        $late = array_filter($means, fn($d) => $d >= $lateStart, ARRAY_FILTER_USE_KEY);
        $frequencyReady = $half >= 7 && $earlyRecorded >= 3 && $lateRecorded >= 3;
        $strengthReady = $frequencyReady && count($early) >= 3 && count($late) >= 3;
        $longest = 0; $run = 0; $previous = null; $start = null; $bestStart = null; $bestEnd = null;
        foreach (array_keys($dates) as $date) {
            if ($previous !== null && daysBetween($previous, $date) === 1) $run++;
            else { $run = 1; $start = $date; }
            if ($run > $longest) { $longest = $run; $bestStart = $start; $bestEnd = $date; }
            $previous = $date;
        }
        $first = $dates ? array_key_first($dates) : null; $last = $dates ? array_key_last($dates) : null;
        $rows[] = array_merge($symptom, [
            'days' => count($selected), 'entries' => array_sum(array_map('count', $selected)),
            'share' => $recorded ? count($selected) / count($recorded) * 100 : null,
            'average' => $means ? array_sum($means) / count($means) : null,
            'frequency_ready' => $frequencyReady, 'strength_ready' => $strengthReady,
            'early_days' => count($early), 'late_days' => count($late),
            'early_share' => $frequencyReady ? count($early) / $earlyRecorded * 100 : null,
            'late_share' => $frequencyReady ? count($late) / $lateRecorded * 100 : null,
            'strength_change' => $strengthReady ? array_sum($late) / count($late) - array_sum($early) / count($early) : null,
            'first' => $first, 'last' => $last, 'span' => $first ? daysBetween($first, $last) + 1 : 0,
            'since' => $last ? daysBetween($last, $today) : null, 'longest' => $longest,
            'streak_start' => $bestStart, 'streak_end' => $bestEnd,
            'recent_streak' => $last && daysBetween($last, $today) <= 1 ? $run : 0,
        ]);
    }
    $pairs = [];
    for ($i = 0; $i < count($rows); $i++) for ($j = $i + 1; $j < count($rows); $j++) {
        $a = array_filter(array_keys($daily[$rows[$i]['id']] ?? []), fn($d) => $d >= $from && $d <= $to);
        $b = array_filter(array_keys($daily[$rows[$j]['id']] ?? []), fn($d) => $d >= $from && $d <= $to);
        $shared = count(array_intersect($a, $b)); $union = count(array_unique(array_merge($a, $b)));
        if ($union) $pairs[] = ['a'=>$rows[$i]['name'], 'b'=>$rows[$j]['name'], 'shared'=>$shared, 'union'=>$union,
            'a_days'=>count($a), 'b_days'=>count($b), 'share'=>$shared / $union * 100, 'ready'=>$shared >= 3 && $union >= 5];
    }
    usort($pairs, fn($a,$b) => $b['shared'] <=> $a['shared'] ?: strcmp($a['a'].$a['b'], $b['a'].$b['b']));
    return ['symptoms'=>$rows, 'pairs'=>$pairs, 'recorded_days'=>count($recorded), 'calendar_days'=>$length,
        'half'=>$half, 'early_end'=>$earlyEnd, 'late_start'=>$lateStart, 'early_recorded'=>$earlyRecorded, 'late_recorded'=>$lateRecorded];
}
