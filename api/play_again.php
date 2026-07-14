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
$host = require_player($pdo, (int) $game['id'], $token);

if (!$host['is_host']) {
    json_error('Only the host can start a new round', 403);
}
if ($game['status'] !== 'finished') {
    json_error('The current game has not finished yet');
}

$maxAttempts = 6;
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE players SET hand = "[]", books = "[]", is_spectator = 0 WHERE game_id = ? AND status = "active"');
        $stmt->execute([$game['id']]);

        $stmt = $pdo->prepare('UPDATE games SET status = ?, deck = "[]", turn_player_id = NULL, turn_state = ?, turn_deadline = NULL,
            pending_asker_id = NULL, pending_target_id = NULL, pending_fish = NULL,
            tiebreak_player_ids = "[]", tiebreak_deck = "[]", tiebreak_turn_index = 0,
            claimed_sets_by_left = 0, winner_player_id = NULL, updated_at = datetime("now") WHERE id = ?');
        $stmt->execute(['waiting', 'awaiting_ask', $game['id']]);

        push_event($pdo, (int) $game['id'], ['type' => 'new_round', 'by' => $host['name']]);

        $pdo->commit();
        break;
    } catch (Throwable $e) {
        $pdo->rollBack();
        if (is_db_busy_error($e) && $attempt < $maxAttempts) {
            db_retry_backoff($attempt);
            continue;
        }
        json_error('Could not start a new round: ' . $e->getMessage(), 500);
    }
}

json_out(['ok' => true]);
