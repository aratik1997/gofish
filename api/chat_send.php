<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

$body = read_json_body();
$roomCode = trim((string) ($body['room_code'] ?? ''));
$token = trim((string) ($body['token'] ?? ''));
$message = trim((string) ($body['message'] ?? ''));

if ($message === '') {
    json_error('Message is empty');
}
if (mb_strlen($message) > 200) {
    $message = mb_substr($message, 0, 200);
}

$pdo = db();
$game = require_game($pdo, $roomCode);
$me = require_player($pdo, (int) $game['id'], $token);

if ($me['status'] !== 'active') {
    json_error('You are not in this game', 403);
}

$maxAttempts = 6;
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    try {
        $stmt = $pdo->prepare('INSERT INTO chat_messages (game_id, player_id, name, message) VALUES (?, ?, ?, ?)');
        $stmt->execute([$game['id'], $me['id'], $me['name'], $message]);
        break;
    } catch (Throwable $e) {
        if (is_db_busy_error($e) && $attempt < $maxAttempts) {
            db_retry_backoff($attempt);
            continue;
        }
        json_error('Could not send message: ' . $e->getMessage(), 500);
    }
}

json_out(['ok' => true]);
