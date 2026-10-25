# Daywell — PHP symptom tracker

A responsive, single-user symptom journal built with PHP and SQLite. No framework, Composer packages, build step, external fonts, or third-party services are required.

## Run locally

Requires PHP 8.1+ with `pdo_sqlite` and `mbstring` enabled.

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

To back up, stop the app and copy `data/symptoms.sqlite`; restore the file while the app is stopped. The SQLite file contains all symptom and diary data. No analytics or remote requests are made.

## Verification

```sh
php tests/smoke.php
```

The smoke test starts an isolated local PHP server against a temporary SQLite database and exercises forms, validation, editing, deletion, and statistics. It does not alter your real database.
