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
$player = require_player($pdo, (int) $game['id'], $token);

if (!$player['is_host']) {
    json_error('Only the host can start the game', 403);
}
if ($game['status'] !== 'waiting') {
    json_error('Game already started');
}

$players = all_players($pdo, (int) $game['id']);
if (count($players) < 2) {
    json_error('Need at least 2 players to start');
}

$deck = build_deck();

$pdo->beginTransaction();
try {
    foreach ($players as $p) {
        $hand = [];
        for ($i = 0; $i < 5; $i++) {
            $hand[] = array_pop($deck);
        }
        [$hand, $setsFormed] = extract_books($hand);
        save_player($pdo, (int) $p['id'], $hand, $setsFormed);
    }

    $firstPlayer = $players[0];
    $stmt = $pdo->prepare('UPDATE games SET status = ?, deck = ?, turn_player_id = ?, turn_state = ?, turn_deadline = ?, claimed_sets_by_left = 0, updated_at = datetime("now") WHERE id = ?');
    $stmt->execute(['playing', j_encode($deck), $firstPlayer['id'], 'awaiting_ask', new_turn_deadline(), $game['id']]);

    push_event($pdo, (int) $game['id'], ['type' => 'game_started']);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Could not start game', 500);
}

json_out(['ok' => true]);
