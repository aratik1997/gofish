<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cards.php';
require_once __DIR__ . '/../includes/Game.php';

$roomCode = trim((string) ($_GET['room_code'] ?? ''));
$token = trim((string) ($_GET['token'] ?? ''));
if ($roomCode === '' || $token === '') {
    json_error('room_code and token are required');
}

$pdo = db();
$game = require_game($pdo, $roomCode);
$me = require_player($pdo, (int) $game['id'], $token);

if ($me['status'] !== 'active') {
    json_out([
        'ok' => true,
        'removed' => true,
        'reason' => $me['status'], // 'left' | 'kicked'
        'by' => $me['left_by'],
    ]);
}

// mark presence -- best-effort; a busy database here just means presence is
// a beat stale until the next poll, not worth failing the whole request over.
try {
    $stmt = $pdo->prepare('UPDATE players SET last_seen = datetime("now"), connected = 1 WHERE id = ?');
    $stmt->execute([$me['id']]);
} catch (Throwable $e) {
    if (!is_db_busy_error($e)) {
        throw $e;
    }
}

$game = check_and_apply_timeout($pdo, $game);

$players = all_players($pdo, (int) $game['id']);
$deck = j_decode($game['deck']);
$tiebreakDeck = j_decode($game['tiebreak_deck']);

$playersOut = [];
foreach ($players as $p) {
    $hand = j_decode($p['hand']);
    $playersOut[] = [
        'id' => (int) $p['id'],
        'name' => $p['name'],
        'seat_order' => (int) $p['seat_order'],
        'is_host' => (bool) $p['is_host'],
        'is_spectator' => (bool) $p['is_spectator'],
        'hand_count' => count($hand),
        'books' => j_decode($p['books']),
        'connected' => (bool) $p['connected'],
    ];
}

$stmt = $pdo->prepare('SELECT id, name, message, created_at FROM chat_messages WHERE game_id = ? ORDER BY id DESC LIMIT 40');
$stmt->execute([$game['id']]);
$chat = array_reverse($stmt->fetchAll());

json_out([
    'ok' => true,
    'server_time' => time(),
    'game' => [
        'room_code' => $game['room_code'],
        'status' => $game['status'],
        'turn_player_id' => $game['turn_player_id'] !== null ? (int) $game['turn_player_id'] : null,
        'turn_state' => $game['turn_state'],
        'turn_deadline_ts' => $game['turn_deadline'] !== null ? strtotime($game['turn_deadline']) : null,
        'pending_asker_id' => $game['pending_asker_id'] !== null ? (int) $game['pending_asker_id'] : null,
        'pending_target_id' => $game['pending_target_id'] !== null ? (int) $game['pending_target_id'] : null,
        'pending_fish' => $game['pending_fish'],
        'deck_count' => count($deck),
        'event_seq' => (int) $game['event_seq'],
        'last_event' => j_decode($game['last_event']),
        'winner_player_id' => $game['winner_player_id'] !== null ? (int) $game['winner_player_id'] : null,
        'tiebreak_player_ids' => j_decode($game['tiebreak_player_ids']),
        'tiebreak_turn_index' => (int) $game['tiebreak_turn_index'],
        'tiebreak_deck_count' => count($tiebreakDeck),
    ],
    'players' => $playersOut,
    'me' => [
        'id' => (int) $me['id'],
        'name' => $me['name'],
        'seat_order' => (int) $me['seat_order'],
        'is_host' => (bool) $me['is_host'],
        'is_spectator' => (bool) $me['is_spectator'],
        'hand' => j_decode($me['hand']),
        'books' => j_decode($me['books']),
    ],
    'chat' => $chat,
    'fish_types' => fish_types(),
]);
