<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

$body = read_json_body();
$name = trim((string) ($body['name'] ?? ''));
if ($name === '') {
    json_error('Name is required');
}
if (mb_strlen($name) > 20) {
    $name = mb_substr($name, 0, 20);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $roomCode = gen_room_code();
    // extremely unlikely, but guard against collision
    for ($i = 0; $i < 5; $i++) {
        $stmt = $pdo->prepare('SELECT id FROM games WHERE room_code = ?');
        $stmt->execute([$roomCode]);
        if (!$stmt->fetch()) {
            break;
        }
        $roomCode = gen_room_code();
    }

    $stmt = $pdo->prepare('INSERT INTO games (room_code, status) VALUES (?, ?)');
    $stmt->execute([$roomCode, 'waiting']);
    $gameId = (int) $pdo->lastInsertId();

    $token = gen_token();
    $stmt = $pdo->prepare('INSERT INTO players (game_id, name, token, seat_order, is_host) VALUES (?, ?, ?, 0, 1)');
    $stmt->execute([$gameId, $name, $token]);
    $playerId = (int) $pdo->lastInsertId();

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Could not create game', 500);
}

json_out([
    'ok' => true,
    'room_code' => $roomCode,
    'token' => $token,
    'player_id' => $playerId,
]);
