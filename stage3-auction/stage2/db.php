<?php
declare(strict_types=1);
// reading the local settings from .env
function load_env(): void {
    $file = dirname(__DIR__) . '/.env';
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        // skipping empty lines and comments
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        // ignoring invalid names and leaving existing settings alone
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) || getenv($key) !== false) continue;
        $value = trim($value);
        // removing matching quotes around the value
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) $value = substr($value, 1, -1);
        putenv($key . '=' . $value);
    }
}
// opening the database connection
function connect_db(): PDO {
    load_env();
    // using a local SQLite file when no database is configured
    $dsn = getenv('DB_DSN') ?: 'sqlite:' . dirname(__DIR__) . '/storage/auction.sqlite';
    if (!str_starts_with($dsn, 'sqlite:') && !str_starts_with($dsn, 'mysql:')) throw new RuntimeException('Use a MySQL or SQLite DSN.');
    // reporting query errors and reading results by column name
    $pdo = new PDO($dsn, getenv('DB_USER') ?: '', getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        // checking foreign keys in SQLite
        $pdo->exec('PRAGMA foreign_keys = ON');
        // waiting up to ten seconds when the database is locked
        $pdo->exec('PRAGMA busy_timeout = 10000');
    } else {
        // using UTC and repeatable reads for MySQL
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
    }
    return $pdo;
}
