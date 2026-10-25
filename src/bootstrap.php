<?php
declare(strict_types=1);

date_default_timezone_set('Europe/London');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');

function db(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    $path = getenv('SYMPTOM_DB_PATH') ?: dirname(__DIR__) . '/data/symptoms.sqlite';
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    $db->exec("CREATE TABLE IF NOT EXISTS symptoms (
        id INTEGER PRIMARY KEY, name TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK(length(name) BETWEEN 1 AND 100),
        notes TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS entries (
        id INTEGER PRIMARY KEY, symptom_id INTEGER NOT NULL REFERENCES symptoms(id) ON DELETE RESTRICT,
        experienced_on TEXT NOT NULL, strength INTEGER NOT NULL CHECK(strength BETWEEN 1 AND 10),
        notes TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE INDEX IF NOT EXISTS entries_date ON entries(experienced_on);
    CREATE INDEX IF NOT EXISTS entries_symptom ON entries(symptom_id);");
    return $db;
}
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function query(string $sql, array $params = []): PDOStatement { $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt; }
function all(string $sql, array $params = []): array { return query($sql, $params)->fetchAll(); }
function one(string $sql, array $params = []): array|false { return query($sql, $params)->fetch(); }
function scalar(string $sql, array $params = []): mixed { return query($sql, $params)->fetchColumn(); }
function validDate(string $value): bool { $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $d !== false && $d->format('Y-m-d') === $value; }
function csrf(): string { $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function redirect(string $page, string $message): never { $_SESSION['flash'] = $message; header('Location: ?page=' . $page, true, 303); exit; }
function dateLabel(string $date): string { return (new DateTimeImmutable($date))->format('j M Y'); }
