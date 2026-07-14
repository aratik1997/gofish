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
if (!is_valid_fish($fish)) {
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

$targetHand = j_decode($target['hand']);
$matchCount = count(array_filter($targetHand, fn($f) => $f === $fish));

$maxAttempts = 6;
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $pdo->beginTransaction();
    try {
        if ($matchCount > 0) {
            // Target hands over every card of that type.
            $targetHand = array_values(array_filter($targetHand, fn($f) => $f !== $fish));
            for ($i = 0; $i < $matchCount; $i++) {
                $askerHand[] = $fish;
            }
            save_player($pdo, (int) $target['id'], $targetHand, j_decode($target['books']));

            // If handing those cards over emptied the target's whole hand, they
            // refill immediately -- at any cost, they don't wait for their own turn.
            $deck = j_decode($game['deck']);
            if (count($targetHand) === 0) {
                refill_if_empty($pdo, (int) $game['id'], (int) $target['id'], $deck);
            }

            [$askerHand, $setsFormed] = extract_books($askerHand);
            $askerBooks = j_decode($asker['books']);
            foreach ($setsFormed as $s) {
                $askerBooks[] = $s;
            }
            save_player($pdo, (int) $asker['id'], $askerHand, $askerBooks);

            push_event($pdo, (int) $game['id'], [
                'type' => 'caught',
                'asker_id' => (int) $asker['id'],
                'target_id' => (int) $target['id'],
                'fish' => $fish,
                'count' => $matchCount,
                'sets_formed' => $setsFormed,
            ]);

            // The asker keeps their turn — but route through start_next_turn so that
            // if the catch cleaned out their entire hand (e.g. it completed a set
            // using every card they had), they still refill from the pond or get
            // skipped, instead of being left stuck with an empty hand.
            start_next_turn($pdo, (int) $game['id'], (int) $asker['id'], $deck);

            $freshGame = require_game($pdo, $roomCode);
            check_game_end($pdo, $freshGame);
        } else {
            $stmt = $pdo->prepare('UPDATE games SET turn_state = ?, turn_deadline = ?, pending_asker_id = ?, pending_target_id = ?, pending_fish = ?, updated_at = datetime("now") WHERE id = ?');
            $stmt->execute(['awaiting_gofish', new_turn_deadline(), $asker['id'], $target['id'], $fish, $game['id']]);

            push_event($pdo, (int) $game['id'], [
                'type' => 'go_fish_prompt',
                'asker_id' => (int) $asker['id'],
                'target_id' => (int) $target['id'],
                'fish' => $fish,
            ]);
        }

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
