<?php
declare(strict_types=1);

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
    $db->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INTEGER PRIMARY KEY, endpoint TEXT NOT NULL UNIQUE, p256dh TEXT NOT NULL, auth TEXT NOT NULL,
        reminder_time TEXT NOT NULL DEFAULT '20:00', timezone TEXT NOT NULL DEFAULT 'Europe/London',
        skip_logged INTEGER NOT NULL DEFAULT 1, enabled INTEGER NOT NULL DEFAULT 1,
        last_sent_date TEXT, last_attempt_at INTEGER NOT NULL DEFAULT 0,
        last_test_at INTEGER NOT NULL DEFAULT 0, last_error TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    return $db;
}
function query(string $sql, array $params = []): PDOStatement { $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt; }
function all(string $sql, array $params = []): array { return query($sql, $params)->fetchAll(); }
function one(string $sql, array $params = []): array|false { return query($sql, $params)->fetch(); }
function scalar(string $sql, array $params = []): mixed { return query($sql, $params)->fetchColumn(); }
