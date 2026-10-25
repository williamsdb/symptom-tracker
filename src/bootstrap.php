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

require_once __DIR__ . '/database.php';
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function validDate(string $value): bool { $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $d !== false && $d->format('Y-m-d') === $value; }
function csrf(): string { $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function redirect(string $page, string $message): never { $_SESSION['flash'] = $message; header('Location: ?page=' . $page, true, 303); exit; }
function dateLabel(string $date): string { return (new DateTimeImmutable($date))->format('j M Y'); }
