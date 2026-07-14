<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cards.php';
require_once __DIR__ . '/../includes/Game.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

$body = read_json_body();
$roomCode = trim((string) ($body['room_code'] ?? ''));
$token = trim((string) ($body['token'] ?? ''));

$pdo = db();
$game = require_game($pdo, $roomCode);
$me = require_player($pdo, (int) $game['id'], $token);

if ($me['status'] !== 'active') {
    json_out(['ok' => true]);
}

$maxAttempts = 6;
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $pdo->beginTransaction();
    try {
        remove_player_from_game($pdo, $game, $me, 'left', null);
        $pdo->commit();
        break;
    } catch (Throwable $e) {
        $pdo->rollBack();
        if (is_db_busy_error($e) && $attempt < $maxAttempts) {
            db_retry_backoff($attempt);
            continue;
        }
        json_error('Could not leave game: ' . $e->getMessage(), 500);
    }
}

json_out(['ok' => true]);
