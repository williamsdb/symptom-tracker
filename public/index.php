<?php
require dirname(__DIR__) . '/src/bootstrap.php';
$pages = ['home' => 'Overview', 'log' => 'Symptom diary', 'symptoms' => 'My symptoms', 'stats' => 'Statistics'];
$page = (string)($_GET['page'] ?? 'home');
if (!isset($pages[$page])) { http_response_code(404); $page = 'home'; }
$error = '';
try {
    db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
            http_response_code(403); throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        $action = (string)($_POST['action'] ?? '');
        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
        $notes = trim((string)($_POST['notes'] ?? ''));
        if (strlen($notes) > 10000) throw new RuntimeException('Please keep notes under 10,000 characters.');
        if ($action === 'save_symptom') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) throw new RuntimeException('Enter a symptom name of 1–100 characters.');
            if ($id && !one('SELECT id FROM symptoms WHERE id = ?', [$id])) throw new RuntimeException('That symptom no longer exists.');
            if (scalar('SELECT COUNT(*) FROM symptoms WHERE name = ? COLLATE NOCASE AND id != ?', [$name, $id])) throw new RuntimeException('A symptom with that name already exists.');
            if ($id) query('UPDATE symptoms SET name = ?, notes = ? WHERE id = ?', [$name, $notes, $id]);
            else query('INSERT INTO symptoms (name, notes) VALUES (?, ?)', [$name, $notes]);
            redirect('symptoms', $id ? 'Symptom updated.' : 'Symptom added. You can now use it in your diary.');
        } elseif ($action === 'delete_symptom') {
            if (scalar('SELECT COUNT(*) FROM entries WHERE symptom_id = ?', [$id])) throw new RuntimeException('This symptom has diary entries. Remove those entries before deleting the symptom.');
            query('DELETE FROM symptoms WHERE id = ?', [$id]);
            redirect('symptoms', 'Symptom deleted.');
        } elseif ($action === 'save_entry') {
            $date = (string)($_POST['experienced_on'] ?? '');
            $symptomId = filter_var($_POST['symptom_id'] ?? '', FILTER_VALIDATE_INT);
            $strength = filter_var($_POST['strength'] ?? '', FILTER_VALIDATE_INT);
            if (!validDate($date) || $date > date('Y-m-d')) throw new RuntimeException('Choose a valid date that is today or earlier.');
            if (!$symptomId || !one('SELECT id FROM symptoms WHERE id = ?', [$symptomId])) throw new RuntimeException('Select a symptom from your saved symptom list.');
            if ($strength === false || $strength < 1 || $strength > 10) throw new RuntimeException('Strength must be a whole number from 1 to 10.');
            if ($id && !one('SELECT id FROM entries WHERE id = ?', [$id])) throw new RuntimeException('That diary entry no longer exists.');
            if ($id) query('UPDATE entries SET experienced_on = ?, symptom_id = ?, strength = ?, notes = ? WHERE id = ?', [$date, $symptomId, $strength, $notes, $id]);
            else query('INSERT INTO entries (experienced_on, symptom_id, strength, notes) VALUES (?, ?, ?, ?)', [$date, $symptomId, $strength, $notes]);
            redirect('log&date=' . urlencode($date), $id ? 'Diary entry updated.' : 'Diary entry saved. Add another symptom below whenever you need to.');
        } elseif ($action === 'delete_entry') {
            query('DELETE FROM entries WHERE id = ?', [$id]);
            redirect('log', 'Diary entry deleted.');
        } else throw new RuntimeException('Unknown action. Please try again.');
    }
} catch (RuntimeException $ex) {
    if ($ex instanceof PDOException) {
        error_log((string)$ex);
        http_response_code(503);
        exit('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Database unavailable · Daywell</title><link rel="icon" href="favicon.ico" sizes="32x32"><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><link rel="apple-touch-icon" href="assets/apple-touch-icon.png"><link rel="stylesheet" href="assets/style.css"><body><section class="card"><h1>Database unavailable</h1><p>Check that SQLite is enabled and the data folder is writable, then reload this page.</p></section></body></html>');
    }
    else $error = $ex->getMessage();
}
$symptoms = all('SELECT s.*, (SELECT COUNT(*) FROM entries WHERE symptom_id=s.id) AS entry_count FROM symptoms s ORDER BY name COLLATE NOCASE');
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$editing = false;
if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    if ($page === 'symptoms') $editing = one('SELECT * FROM symptoms WHERE id = ?', [(int)$_GET['edit']]);
    if ($page === 'log') $editing = one('SELECT * FROM entries WHERE id = ?', [(int)$_GET['edit']]);
}
$form = $error && $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($editing ?: []);
function entryRows(array $entries): void {
    foreach ($entries as $entry) { ?>
    <article class="entry-row">
        <div class="entry-date"><strong><?= e((new DateTimeImmutable($entry['experienced_on']))->format('d')) ?></strong><span><?= e((new DateTimeImmutable($entry['experienced_on']))->format('M Y')) ?></span></div>
        <div class="entry-copy"><h3><?= e($entry['name']) ?></h3><p><?= $entry['notes'] !== '' ? nl2br(e($entry['notes'])) : '<span class="muted">No notes added</span>' ?></p></div>
        <span class="strength level-<?= (int)ceil($entry['strength'] / 3.4) ?>"><?= (int)$entry['strength'] ?><small>/10</small></span>
        <a class="text-link" href="?page=log&amp;edit=<?= (int)$entry['id'] ?>">Edit<span class="sr-only"> <?= e($entry['name']) ?> entry</span></a>
    </article>
<?php } }
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#183e36"><title><?= e($pages[$page]) ?> · Daywell</title><link rel="icon" href="favicon.ico" sizes="32x32"><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><link rel="apple-touch-icon" href="assets/apple-touch-icon.png"><link rel="stylesheet" href="assets/style.css"><script src="assets/app.js" defer></script></head>
<body><div class="app-shell">
<aside class="sidebar"><a class="brand" href="?page=home"><span class="brand-mark">✳</span> daywell<span class="brand-dot">.</span></a><p class="brand-caption">YOUR SYMPTOM JOURNAL</p>
<nav aria-label="Main navigation"><?php foreach ($pages as $key => $label): ?><a <?= $page === $key ? 'aria-current="page"' : '' ?> href="?page=<?= e($key) ?>"><span aria-hidden="true"><?= ['home'=>'◫', 'log'=>'☷', 'symptoms'=>'✧', 'stats'=>'▥'][$key] ?></span><?= e($label) ?></a><?php endforeach ?></nav>
<div class="sidebar-note"><span aria-hidden="true">◉</span><strong>A little clarity, every day.</strong><p>A space to notice patterns and keep track of how you feel.</p><small>Stored locally in SQLite</small></div></aside>
<main><header class="topbar"><span>Your health, at a glance</span><time datetime="<?= date('Y-m-d') ?>"><?= date('l, j F Y') ?></time></header>
<?php if ($flash): ?><div class="notice success" role="status"><?= e($flash) ?></div><?php endif ?>
<?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif ?>
<?php if ($page === 'home'):
$total = (int)scalar('SELECT COUNT(*) FROM entries');
$days = (int)scalar('SELECT COUNT(DISTINCT experienced_on) FROM entries');
$average = scalar('SELECT ROUND(AVG(strength), 1) FROM entries');
$recent = all('SELECT e.*, s.name FROM entries e JOIN symptoms s ON s.id=e.symptom_id ORDER BY experienced_on DESC, e.id DESC LIMIT 5');
?>
<section class="page-heading"><div><p class="eyebrow">A MOMENT FOR YOURSELF</p><h1>A clearer picture of you.</h1><p>Small observations today. Helpful patterns over time.</p></div><a class="button primary" href="?page=log">＋ Log a symptom</a></section>
<section class="welcome"><div><span class="pill">YOUR DAILY CHECK-IN</span><h2>How are you feeling today?</h2><p>Take a moment to record what you’re experiencing.<br>Every entry helps build your personal picture.</p><a class="button light" href="?page=log">Add a diary entry <span aria-hidden="true">↗</span></a></div><div class="welcome-art" aria-hidden="true"><div class="orbit one"></div><div class="orbit two"></div><span>✳</span><small>ONE DAY AT A TIME</small></div></section>
<div class="metric-grid"><article class="metric"><span>Total entries <b>☷</b></span><strong><?= $total ?></strong><small>Your observations so far</small></article><article class="metric"><span>Days recorded <b>▦</b></span><strong><?= $days ?></strong><small>A little consistency goes a long way</small></article><article class="metric"><span>Average strength <b>◴</b></span><strong><?= $average === null ? '—' : e($average) ?><em>/ 10</em></strong><small>Across all recorded symptoms</small></article></div>
<section class="card"><div class="section-heading"><div><h2>Recent entries</h2><p>Your latest check-ins, in one place.</p></div><a class="text-link" href="?page=log">View diary →</a></div><?php if ($recent): entryRows($recent); else: ?><div class="empty"><span>✧</span><h3>Your story starts here</h3><p>Create your symptom list, then record your first check-in.</p><a class="button secondary" href="?page=symptoms">Add your first symptom</a></div><?php endif ?></section>
<div class="bottom-note"><span>✧</span><p>Your journal is personal. All entries are saved in the local database on this server.</p></div>
<?php elseif ($page === 'symptoms'): ?>
<section class="page-heading"><div><p class="eyebrow">BUILD YOUR PERSONAL LIST</p><h1>My symptoms</h1><p>Keep a list of the symptoms you want to track.</p></div><span class="count-label"><?= count($symptoms) ?> saved</span></section>
<div class="two-column"><section class="card form-card"><h2><?= $editing ? 'Edit symptom' : 'Add a symptom' ?></h2><p>These symptoms will be available in your diary.</p>
<form method="post"><?= csrf() ?><input type="hidden" name="action" value="save_symptom"><input type="hidden" name="id" value="<?= e($form['id'] ?? 0) ?>"><label for="name">Symptom name</label><input id="name" name="name" maxlength="100" required placeholder="e.g. Headache" value="<?= e($form['name'] ?? '') ?>"><label for="notes">Notes <span>(optional)</span></label><textarea id="notes" name="notes" rows="5" maxlength="10000" placeholder="Describe this symptom or what you’d like to keep an eye on."><?= e($form['notes'] ?? '') ?></textarea><button class="button primary" type="submit"><?= $editing ? 'Save changes' : '＋ Add symptom' ?></button><?php if ($editing): ?><a class="cancel" href="?page=symptoms">Cancel</a><?php endif ?></form></section>
<section class="card"><div class="section-heading"><h2>Your symptom list</h2></div><?php if (!$symptoms): ?><div class="empty"><span>✧</span><h3>A list that’s yours</h3><p>Add a symptom using the form to get started.</p></div><?php endif ?>
<?php foreach ($symptoms as $symptom): ?><article class="symptom-item"><div><h3><?= e($symptom['name']) ?></h3><p><?= nl2br(e($symptom['notes'])) ?></p><small><?= (int)$symptom['entry_count'] ?> diary <?= (int)$symptom['entry_count'] === 1 ? 'entry' : 'entries' ?></small></div><div class="item-actions"><a class="text-link" href="?page=symptoms&amp;edit=<?= (int)$symptom['id'] ?>">Edit<span class="sr-only"> <?= e($symptom['name']) ?></span></a><?php if (!$symptom['entry_count']): ?><form method="post" data-confirm="Delete this symptom?"><?= csrf() ?><input type="hidden" name="action" value="delete_symptom"><input type="hidden" name="id" value="<?= (int)$symptom['id'] ?>"><button class="danger-link" type="submit">Delete<span class="sr-only"> <?= e($symptom['name']) ?></span></button></form><?php endif ?></div></article><?php endforeach ?></section></div>
<?php elseif ($page === 'log'):
$filterDate = (string)($_GET['date'] ?? '');
if ($filterDate !== '' && !validDate($filterDate)) $filterDate = '';
$offset = max(0, (int)($_GET['offset'] ?? 0));
$where = $filterDate ? ' WHERE experienced_on = ?' : '';
$params = $filterDate ? [$filterDate] : [];
$entryCount = (int)scalar('SELECT COUNT(*) FROM entries' . $where, $params);
$entries = all('SELECT e.*, s.name FROM entries e JOIN symptoms s ON s.id=e.symptom_id' . $where . ' ORDER BY experienced_on DESC, e.id DESC LIMIT 30 OFFSET ' . $offset, $params);
?>
<section class="page-heading"><div><p class="eyebrow">ONE CHECK-IN AT A TIME</p><h1>Symptom diary</h1><p>Record what you feel, and anything you’d like to remember.</p></div></section>
<div class="two-column"><section class="card form-card"><h2><?= $editing ? 'Edit diary entry' : 'Log a symptom' ?></h2><p>Add as many entries as you need for each day.</p>
<?php if (!$symptoms): ?><div class="empty"><h3>Create your symptom list first</h3><p>Your diary uses symptoms from your saved list.</p><a class="button primary" href="?page=symptoms">Add a symptom</a></div><?php else: ?>
<form method="post"><?= csrf() ?><input type="hidden" name="action" value="save_entry"><input type="hidden" name="id" value="<?= e($form['id'] ?? 0) ?>"><label for="experienced_on">Date experienced</label><input type="date" id="experienced_on" name="experienced_on" required max="<?= date('Y-m-d') ?>" value="<?= e($form['experienced_on'] ?? ($filterDate ?: date('Y-m-d'))) ?>"><label for="symptom_id">Symptom</label><select id="symptom_id" name="symptom_id" required><option value="">Choose a symptom</option><?php foreach ($symptoms as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (string)($form['symptom_id'] ?? '') === (string)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach ?></select><label for="strength">Strength <output id="strength-output" for="strength"><?= e($form['strength'] ?? 5) ?> / 10</output></label><input type="range" min="1" max="10" step="1" id="strength" name="strength" value="<?= e($form['strength'] ?? 5) ?>"><div class="range-labels"><span>1 · Mild</span><span>10 · Very strong</span></div><label for="notes">Notes <span>(optional)</span></label><textarea id="notes" name="notes" rows="4" maxlength="10000" placeholder="What was happening? How did you feel?"><?= e($form['notes'] ?? '') ?></textarea><button class="button primary" type="submit"><?= $editing ? 'Save changes' : '＋ Save diary entry' ?></button><?php if ($editing): ?><a class="cancel" href="?page=log">Cancel</a><?php endif ?></form>
<?php if ($editing): ?><form method="post" class="delete-form" data-confirm="Delete this diary entry? This cannot be undone."><?= csrf() ?><input type="hidden" name="action" value="delete_entry"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><button class="danger-link" type="submit">Delete this entry</button></form><?php endif ?><?php endif ?></section>
<section class="card"><div class="section-heading"><div><h2>Your entries</h2><p><?= $entryCount ?> <?= $filterDate ? 'on ' . e(dateLabel($filterDate)) : 'recorded in total' ?></p></div></div><form method="get" class="filter"><input type="hidden" name="page" value="log"><label class="sr-only" for="filter-date">Filter by date</label><input type="date" id="filter-date" name="date" value="<?= e($filterDate) ?>"><button class="button secondary" type="submit">Filter</button><?php if ($filterDate): ?><a class="text-link" href="?page=log">Clear</a><?php endif ?></form>
<?php if ($entries): entryRows($entries); else: ?><div class="empty"><span>☷</span><h3>No entries yet<?= $filterDate ? ' for this date' : '' ?></h3><p>Your saved check-ins will appear here.</p></div><?php endif ?>
<div class="pagination"><?php if ($offset > 0): ?><a class="text-link" href="?page=log&amp;date=<?= e($filterDate) ?>&amp;offset=<?= max(0, $offset-30) ?>">← Newer entries</a><?php endif ?><?php if ($offset+30 < $entryCount): ?><a class="text-link" href="?page=log&amp;date=<?= e($filterDate) ?>&amp;offset=<?= $offset+30 ?>">Older entries →</a><?php endif ?></div></section></div>
<?php elseif ($page === 'stats'):
$from = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-29 days')));
$to = (string)($_GET['to'] ?? date('Y-m-d'));
if (!validDate($from) || !validDate($to) || $from > $to) { $from = date('Y-m-d', strtotime('-29 days')); $to = date('Y-m-d'); echo '<div class="notice error" role="alert">Choose a valid date range with the start before the end. Showing the last 30 days.</div>'; }
$summary = one('SELECT COUNT(*) AS total, COUNT(DISTINCT experienced_on) AS days, ROUND(AVG(strength),1) AS average FROM entries WHERE experienced_on BETWEEN ? AND ?', [$from, $to]);
$breakdown = all('SELECT s.name, COUNT(*) AS count, ROUND(AVG(e.strength),1) AS average, MAX(e.strength) AS peak FROM entries e JOIN symptoms s ON s.id=e.symptom_id WHERE experienced_on BETWEEN ? AND ? GROUP BY s.id ORDER BY count DESC, s.name', [$from, $to]);
$trend = all('SELECT experienced_on, ROUND(AVG(strength),1) AS average, COUNT(*) AS count FROM entries WHERE experienced_on BETWEEN ? AND ? GROUP BY experienced_on ORDER BY experienced_on', [$from, $to]);
?>
<section class="page-heading"><div><p class="eyebrow">SEE THE BIGGER PICTURE</p><h1>Your patterns, over time.</h1><p>A little perspective on what you’ve been experiencing.</p></div></section>
<form method="get" class="card stats-filter"><input type="hidden" name="page" value="stats"><div><label for="from">From</label><input type="date" id="from" name="from" required value="<?= e($from) ?>"></div><div><label for="to">To</label><input type="date" id="to" name="to" required value="<?= e($to) ?>"></div><button class="button primary" type="submit">Update statistics</button><a class="text-link" href="?page=stats">Last 30 days</a></form>
<div class="metric-grid"><article class="metric"><span>Diary entries</span><strong><?= (int)$summary['total'] ?></strong><small>In the selected period</small></article><article class="metric"><span>Days recorded</span><strong><?= (int)$summary['days'] ?></strong><small>Days with at least one entry</small></article><article class="metric"><span>Average strength</span><strong><?= $summary['average'] === null ? '—' : e($summary['average']) ?><em>/ 10</em></strong><small>Each entry has equal weight</small></article></div>
<?php if (!$summary['total']): ?><section class="card empty"><span>▥</span><h2>Patterns take a little time</h2><p>No entries in this date range. Try another range or add a check-in.</p><a class="button secondary" href="?page=log">Log a symptom</a></section><?php else: ?>
<section class="card"><div class="section-heading"><div><h2>Strength over time</h2><p>Average strength on each recorded day. Days without entries are omitted.</p></div></div><div class="chart" aria-label="Daily average symptom strength"><?php foreach ($trend as $day): ?><div class="chart-column"><span><?= e($day['average']) ?></span><div class="bar-track"><meter min="0" max="10" value="<?= e($day['average']) ?>"><?= e($day['average']) ?> out of 10</meter><div class="bar bar-<?= (int)round($day['average'] * 10) ?>"></div></div><small><?= e((new DateTimeImmutable($day['experienced_on']))->format('j M')) ?></small><span class="sr-only"><?= e($day['experienced_on']) ?>: <?= (int)$day['count'] ?> entries</span></div><?php endforeach ?></div></section>
<section class="card"><div class="section-heading"><div><h2>By symptom</h2><p>Frequency and strength in your selected date range.</p></div></div><div class="table-scroll"><table><thead><tr><th scope="col">Symptom</th><th scope="col">Entries</th><th scope="col">Avg. strength</th><th scope="col">Highest</th></tr></thead><tbody><?php foreach ($breakdown as $row): ?><tr><th scope="row"><?= e($row['name']) ?></th><td><?= (int)$row['count'] ?></td><td><?= e($row['average']) ?> <span class="muted">/ 10</span></td><td><?= (int)$row['peak'] ?> <span class="muted">/ 10</span></td></tr><?php endforeach ?></tbody></table></div></section><?php endif ?>
<p class="footnote">These statistics describe your recorded entries and are not a medical assessment.</p>
<?php endif ?>
<footer>daywell. <span>A little clarity, every day.</span></footer></main></div></body></html>
