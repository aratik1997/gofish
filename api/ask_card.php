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
$targetId = (int) ($body['target_id'] ?? 0);
$fish = trim((string) ($body['fish'] ?? ''));

$pdo = db();
$game = require_game($pdo, $roomCode);
$asker = require_player($pdo, (int) $game['id'], $token);
$game = check_and_apply_timeout($pdo, $game);

if ($game['status'] !== 'playing') {
    json_error('Game is not currently in progress');
}
if ($game['turn_state'] !== 'awaiting_ask') {
    json_error('Waiting on a Go Fish response first');
}
if ((int) $game['turn_player_id'] !== (int) $asker['id']) {
    json_error('It is not your turn', 403);
}
if (!is_valid_fish($fish, (int) $game['fish_set_count'])) {
    json_error('Unknown fish type');
}
if ($targetId === (int) $asker['id']) {
    json_error('You cannot ask yourself');
}

$askerHand = j_decode($asker['hand']);
if (!in_array($fish, $askerHand, true)) {
    json_error('You can only ask for a fish you already have at least one of');
}

$stmt = $pdo->prepare('SELECT * FROM players WHERE id = ? AND game_id = ? AND status = "active"');
$stmt->execute([$targetId, $game['id']]);
$target = $stmt->fetch();
if (!$target) {
    json_error('That player is not in this game');
}
if ($target['is_spectator']) {
    json_error('That player is only spectating and has no cards');
}

$maxAttempts = 6;
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $pdo->beginTransaction();
    try {
        perform_ask($pdo, $game, $asker, $target, $fish);
        $pdo->commit();
        break;
    } catch (Throwable $e) {
        $pdo->rollBack();
        if (is_db_busy_error($e) && $attempt < $maxAttempts) {
            db_retry_backoff($attempt);
            continue;
        }
        json_error('Could not process ask: ' . $e->getMessage(), 500);
    }
}

json_out(['ok' => true]);
