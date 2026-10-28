<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dbFile = __DIR__ . '/../data/gofish.sqlite';
    $needsInit = !file_exists($dbFile);

    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 8000');

    if ($needsInit) {
        $schema = file_get_contents(__DIR__ . '/../sql/schema.sql');
        $pdo->exec($schema);
    } else {
        migrate($pdo);
    }

    return $pdo;
}

/**
 * Lightweight idempotent migrations for databases created before a schema
 * change -- runs on every connection but each check is a cheap no-op once
 * applied, so no separate migration-runner script is needed.
 */
function migrate(PDO $pdo): void {
    $columns = $pdo->query('PRAGMA table_info(games)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('fish_set_count', $columns, true)) {
        $pdo->exec('ALTER TABLE games ADD COLUMN fish_set_count INTEGER NOT NULL DEFAULT 13');
    }
    if (!in_array('bot_next_at', $columns, true)) {
        $pdo->exec('ALTER TABLE games ADD COLUMN bot_next_at TEXT');
    }

    $playerColumns = $pdo->query('PRAGMA table_info(players)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('is_bot', $playerColumns, true)) {
        $pdo->exec('ALTER TABLE players ADD COLUMN is_bot INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE players ADD COLUMN bot_difficulty TEXT');
    }
}
