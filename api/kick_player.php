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

$pdo = db();
$game = require_game($pdo, $roomCode);
$host = require_player($pdo, (int) $game['id'], $token);

if (!$host['is_host']) {
    json_error('Only the host can kick players', 403);
}
if ($targetId === (int) $host['id']) {
    json_error('Use Leave to remove yourself');
}

$stmt = $pdo->prepare('SELECT * FROM players WHERE id = ? AND game_id = ? AND status = "active"');
$stmt->execute([$targetId, $game['id']]);
$target = $stmt->fetch();
if (!$target) {
    json_error('That player is not in this game');
}

$maxAttempts = 6;
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $pdo->beginTransaction();
    try {
        remove_player_from_game($pdo, $game, $target, 'kicked', $host['name']);
        $pdo->commit();
        break;
    } catch (Throwable $e) {
        $pdo->rollBack();
        if (is_db_busy_error($e) && $attempt < $maxAttempts) {
            db_retry_backoff($attempt);
            continue;
        }
        json_error('Could not kick player: ' . $e->getMessage(), 500);
    }
}

json_out(['ok' => true]);
