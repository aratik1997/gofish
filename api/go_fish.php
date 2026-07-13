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
$caller = require_player($pdo, (int) $game['id'], $token);
$game = check_and_apply_timeout($pdo, $game);

if ($game['status'] !== 'playing') {
    json_error('Game is not currently in progress');
}
if ($game['turn_state'] !== 'awaiting_gofish') {
    json_error('No Go Fish response is pending');
}
if ((int) $game['pending_target_id'] !== (int) $caller['id']) {
    json_error('Only the asked player can send the asker fishing', 403);
}

$pdo->beginTransaction();
try {
    resolve_go_fish($pdo, $game);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Could not process go fish: ' . $e->getMessage(), 500);
}

json_out(['ok' => true]);
