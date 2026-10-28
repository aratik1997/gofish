<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Game.php';
require_once __DIR__ . '/../includes/Bots.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

$body = read_json_body();
$roomCode = trim((string) ($body['room_code'] ?? ''));
$token = trim((string) ($body['token'] ?? ''));
$difficulty = strtolower(trim((string) ($body['difficulty'] ?? 'medium')));

if (!in_array($difficulty, BOT_DIFFICULTIES, true)) {
    json_error('Bot difficulty must be easy, medium or hard');
}

$pdo = db();
$game = require_game($pdo, $roomCode);
$host = require_player($pdo, (int) $game['id'], $token);

if (!$host['is_host']) {
    json_error('Only the host can add bots', 403);
}
if ($game['status'] !== 'waiting') {
    json_error('Bots can only be added before the game starts');
}

$players = all_players($pdo, (int) $game['id']);
$playing = array_filter($players, fn($p) => !$p['is_spectator']);
if (count($playing) >= MAX_PLAYERS) {
    json_error('This game is full (' . MAX_PLAYERS . ' players max)');
}

// Pick the first "Bot N" name nobody in the room is using.
$taken = array_map(fn($p) => mb_strtolower($p['name']), $players);
$number = 1;
while (in_array(mb_strtolower('Bot ' . $number), $taken, true)) {
    $number++;
}
$name = 'Bot ' . $number;
$seatOrder = count($players);

$maxAttempts = 6;
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO players (game_id, name, token, seat_order, is_host, is_spectator, is_bot, bot_difficulty)
            VALUES (?, ?, ?, ?, 0, 0, 1, ?)');
        $stmt->execute([$game['id'], $name, gen_token(), $seatOrder, $difficulty]);
        $playerId = (int) $pdo->lastInsertId();

        push_event($pdo, (int) $game['id'], ['type' => 'player_joined', 'name' => $name, 'spectator' => false]);

        $pdo->commit();
        break;
    } catch (Throwable $e) {
        $pdo->rollBack();
        if (is_db_busy_error($e) && $attempt < $maxAttempts) {
            db_retry_backoff($attempt);
            continue;
        }
        json_error('Could not add bot: ' . $e->getMessage(), 500);
    }
}

json_out(['ok' => true, 'player_id' => $playerId, 'name' => $name]);
