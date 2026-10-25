# Daywell — PHP symptom tracker

A responsive, single-user symptom journal built with PHP and SQLite. The journal needs no framework or build step. Optional background reminders use a Composer Web Push library and the browser’s push delivery service.

## Run locally

Requires PHP 8.2+ with `pdo_sqlite`, `mbstring`, `curl`, and `openssl` enabled for reminders.

From this directory:

```sh
php -S 127.0.0.1:8080 -t public
```

Open http://127.0.0.1:8080. The database is created automatically at `data/symptoms.sqlite`. The PHP process needs write access to the `data` directory. An alternative existing writable directory/database file can be selected through the `SYMPTOM_DB_PATH` environment variable.

To use on a phone on your trusted local network, bind the server to your computer's LAN address and open that address on your phone. Both devices use the same server database; browser storage is not used.

## Features

- Home overview with recent entries and quick links.
- Create and edit named symptoms with optional notes.
- Record any number of entries per day, each using an existing symptom, a strength of 1–10, and optional notes. Repeat entries for the same symptom/date are allowed.
- Edit and delete diary entries; filter by date with paginated results.
- Delete unused symptoms. Symptoms used in the diary cannot be deleted until their entries are removed.
- Date-filtered statistics: entry count, distinct recorded days, mean strength, daily averages, and symptom frequency/average/peak strength. Missing days are omitted, not counted as zero.
- Responsive layouts, labeled controls, server validation, escaped output, prepared queries, CSRF protection, and database foreign-key/check constraints.

Dates use Europe/London; future diary dates are rejected. Symptom names are unique (SQLite's built-in ASCII case-insensitive comparison). Notes are limited to 10,000 bytes.

## Hosting and data

Configure your web server's **document root as `public/`**, keeping `src/` and `data/` outside it. This app is for one person and has no login or account separation. For access beyond a trusted device/network, configure HTTPS and authentication at the web server or reverse proxy. PHP's development server is for local use only.

To back up, stop the app and copy `data/symptoms.sqlite`; restore the file while the app is stopped. The SQLite file contains all symptom and diary data. No analytics are used. When reminders are enabled, encrypted generic notifications are sent to the browser’s push service; symptom details are never included.

## Verification

```sh
composer install
php tests/smoke.php
php tests/reminders.php
```

The smoke test starts an isolated local PHP server against a temporary SQLite database and exercises forms, validation, editing, deletion, and statistics. It does not alter your real database.

## Daily background reminders

Reminders are configured per browser/device on the **Reminders** page. Your existing journal database is upgraded automatically, preserving entries. The app remains single-user: protect your live installation with your existing web-server authentication. Do not publish the database, VAPID keys, or subscription endpoints.

### Live server setup

1. Upload/pull the updated source, including `composer.json` and `composer.lock`. Keep your existing `data/symptoms.sqlite`.
2. From the project directory, install the locked dependencies:

   ```sh
   composer install --no-dev --prefer-dist --optimize-autoloader
   ```

   If Composer is unavailable on the server, run this locally on a compatible PHP version and upload `vendor/` too. Reminders require PHP 8.2+ and the extensions listed above; the CLI PHP and web PHP must both meet these requirements.

3. Generate this installation's VAPID keys **once**, using your real contact email:

   ```sh
   php bin/setup-reminders.php mailto:you@example.com
   ```

   This writes `data/push-config.php` outside the public directory. It is ignored by Git. The script creates it with owner-only permissions: if cron and web PHP use different accounts, give just those accounts read access using your server's group/ACL configuration. Both must also be able to write the database and its containing directory. Keep the keys stable and back up this file alongside the database; replacing the keys requires devices to subscribe again. No Apple developer membership or paid push provider is required.

4. Add this cron entry, replacing both executable and project paths with your server's absolute paths:

   ```cron
   * * * * * /usr/bin/php /absolute/path/to/daywell/bin/send-reminders.php >> /absolute/path/to/daywell/data/reminders.log 2>&1
   ```

   The script uses paths relative to its installation, so cron's working directory does not matter. If you already use `SYMPTOM_DB_PATH`, set the **same absolute database path** for web PHP and cron. If desired, `DAYWELL_PUSH_CONFIG` selects an alternative absolute configuration path; set it in both environments too. Cron's OS timezone does not matter; each device's chosen timezone controls delivery, including daylight saving changes.

5. Open your HTTPS site, visit **Reminders**, choose a time, enable notifications and press **Send test**. On iPhone/iPad (iOS 16.4+), first add Daywell to the Home Screen and open it from that icon. If it was previously added as a bookmark, remove that shortcut and add it again after deploying this update. Desktop Safari, Chrome, Edge and Firefox can subscribe directly when supported by the OS/browser.

The test button contacts the actual push service; a successful response means it accepted the message, not that the device displayed it. Check Focus settings, notification permission, connectivity and server outbound HTTPS access if it does not arrive. A service worker must be served at `sw.js` with a JavaScript content type; configure your host if necessary. Keep the site on the same HTTPS origin and path after subscribing.

### Scheduling behaviour

- Cron should run every minute. A reminder is eligible from its selected time until the end of that local day. A missed cron run can therefore send later that day.
- Each subscription is sent at most once per local calendar day during normal successful runs. Overlapping jobs are locked. A crash after push acceptance but before recording success can still cause a retry; the notification tag replaces an existing daily notification.
- Failed sends retry after 15 minutes. Expired subscriptions are removed. Other failures appear in the device's reminder settings and the cron summary; run the command manually to check its exit code/output. Cron needs outbound HTTPS to Apple, Google, Mozilla, or Microsoft's push endpoints.
- “Skip if logged” considers any entry for the reminder timezone's current date. Once skipped, that day is complete even if its entry is subsequently deleted. Diary dates themselves still use Europe/London.
- A test does not consume the day's reminder and is limited to one attempt per device per minute. Turning reminders off affects only that device.
- Delivery requires internet access and is subject to browser/OS settings. Push messages expire after one hour to avoid stale reminders. This feature does not make the diary available offline; opening it still requires your server.
- Only the generic check-in message is sent. The worker never caches diary pages or API responses. Browser endpoints and keys remain in SQLite on your server.

Developer reference: [PHP Web Push library](https://github.com/web-push-libs/web-push-php/tree/v10.1.0), [Apple Web Push guidance](https://webkit.org/blog/13878/).
