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
$guess = trim((string) ($body['guess'] ?? ''));

$pdo = db();
$game = require_game($pdo, $roomCode);
$caller = require_player($pdo, (int) $game['id'], $token);
$game = check_and_apply_timeout($pdo, $game);

if ($game['status'] !== 'tiebreak') {
    json_error('No tiebreak in progress');
}
if (!is_valid_fish($guess)) {
    json_error('Unknown fish type');
}

$tiebreakIds = j_decode($game['tiebreak_player_ids']);
$turnIdx = (int) $game['tiebreak_turn_index'];
if (!isset($tiebreakIds[$turnIdx]) || (int) $tiebreakIds[$turnIdx] !== (int) $caller['id']) {
    json_error('It is not your turn to guess', 403);
}

$pdo->beginTransaction();
try {
    resolve_tiebreak_guess($pdo, $game, (int) $caller['id'], $guess);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Could not process guess: ' . $e->getMessage(), 500);
}

json_out(['ok' => true]);
