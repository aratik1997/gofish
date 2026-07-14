<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Game.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

$body = read_json_body();
$name = trim((string) ($body['name'] ?? ''));
$roomCode = trim((string) ($body['room_code'] ?? ''));
if ($name === '' || $roomCode === '') {
    json_error('Name and room code are required');
}
if (mb_strlen($name) > 20) {
    $name = mb_substr($name, 0, 20);
}

$pdo = db();
$game = require_game($pdo, $roomCode);

$players = all_players($pdo, (int) $game['id']);
foreach ($players as $p) {
    if (mb_strtolower($p['name']) === mb_strtolower($name)) {
        json_error('That name is already taken in this game');
    }
}

$isSpectator = $game['status'] !== 'waiting';
if (!$isSpectator) {
    $playing = array_filter($players, fn($p) => !$p['is_spectator']);
    if (count($playing) >= MAX_PLAYERS) {
        json_error('This game is full (' . MAX_PLAYERS . ' players max)');
    }
}

$token = gen_token();
$seatOrder = count($players);

$maxAttempts = 6;
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO players (game_id, name, token, seat_order, is_host, is_spectator) VALUES (?, ?, ?, ?, 0, ?)');
        $stmt->execute([$game['id'], $name, $token, $seatOrder, $isSpectator ? 1 : 0]);
        $playerId = (int) $pdo->lastInsertId();

        push_event($pdo, (int) $game['id'], ['type' => 'player_joined', 'name' => $name, 'spectator' => $isSpectator]);

        $pdo->commit();
        break;
    } catch (Throwable $e) {
        $pdo->rollBack();
        if (is_db_busy_error($e) && $attempt < $maxAttempts) {
            db_retry_backoff($attempt);
            continue;
        }
        json_error('Could not join game: ' . $e->getMessage(), 500);
    }
}

json_out([
    'ok' => true,
    'room_code' => $game['room_code'],
    'token' => $token,
    'player_id' => $playerId,
    'spectator' => $isSpectator,
]);
