<?php
require_once __DIR__ . '/push.php';
$config = pushConfig();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
?>
<section class="page-heading"><div><p class="eyebrow">A LITTLE NUDGE EACH DAY</p><h1>Daily reminders</h1><p>Make a moment for your daily check-in.</p></div></section>
<div class="two-column"><section class="card form-card"><h2>Reminders on this device</h2><p>Each phone or browser has its own reminder settings.</p>
<?php if (!$config): ?><div class="notice error" role="status">Reminders need server setup first. Install the Composer dependencies, generate reminder keys, then set up cron using the README.</div><?php endif ?>
<div id="reminder-status" class="notice" role="status" aria-live="polite">Checking notification support…</div>
<form id="reminder-form" data-public-key="<?= e($config['publicKey'] ?? '') ?>" data-csrf="<?= e($_SESSION['csrf']) ?>">
<label for="reminder-time">Daily reminder time</label><input type="time" id="reminder-time" value="20:00" required>
<label for="reminder-timezone">Timezone</label><select id="reminder-timezone" required><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option value="<?= e($tz) ?>" <?= $tz === 'Europe/London' ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $tz)) ?></option><?php endforeach ?></select>
<label class="check-label"><input type="checkbox" id="skip-logged" checked> Skip if I’ve already logged an entry for that date</label>
<div class="reminder-buttons"><button type="submit" class="button primary" id="enable-reminders" disabled>Enable / save reminders</button><button type="button" class="button secondary" id="test-reminder" disabled>Send test</button><button type="button" class="danger-link" id="disable-reminders" disabled>Turn off</button></div>
</form><noscript><p>Enable JavaScript to configure notifications.</p></noscript></section>
<section class="card"><h2>Set up your iPhone</h2><ol class="setup-steps"><li>Open Daywell in Safari, tap Share, then <strong>Add to Home Screen</strong>.</li><li>Open Daywell using its Home Screen icon.</li><li>Choose your time here, tap <strong>Enable / save reminders</strong>, and allow notifications.</li><li>Tap <strong>Send test</strong> to check delivery.</li></ol><p class="footnote">Requires iOS 16.4 or later. Desktop browsers can subscribe directly. HTTPS is required.</p><p class="footnote">Notifications can arrive with the app closed. Internet connectivity and Focus or notification settings may affect delivery. Tapping a notification opens the diary.</p><p class="footnote">Only a generic check-in message is sent through your browser’s push provider. Your symptoms and notes stay in the server’s SQLite database.</p><p class="footnote">The selected timezone follows daylight saving changes. “Skip” checks diary entries against that timezone’s current date; diary date entry still uses Europe/London.</p></section></div>
