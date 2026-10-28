<?php
declare(strict_types=1);

require_once __DIR__ . '/cards.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Game.php';

const BOT_DIFFICULTIES = ['easy', 'medium', 'hard'];

/** Seconds a bot "thinks" before each action, so it reads as a person rather than instant. */
const BOT_ASK_DELAY = [
    'easy'   => [4, 7],
    'medium' => [3, 5],
    'hard'   => [2, 4],
];
const BOT_RESPOND_DELAY = [1, 2];

/**
 * Chooses [target, fish] for a bot's ask. Uses only information every player can see: the bot's own
 * hand and the other players' hand counts.
 *  - easy:   random fish it holds, random opponent.
 *  - medium: random fish it holds, the opponent with the most cards.
 *  - hard:   the fish it holds most copies of (closest to a book), the opponent with the most cards.
 * Returns null when there is nothing legal to ask (empty hand or no opponents).
 */
function bot_choose_ask(array $hand, array $others, string $difficulty): ?array {
    if (empty($hand) || empty($others)) {
        return null;
    }

    $counts = array_count_values($hand);
    if ($difficulty === 'hard') {
        $most = max($counts);
        $fishChoices = array_keys(array_filter($counts, fn($c) => $c === $most));
    } else {
        $fishChoices = array_keys($counts);
    }
    $fish = (string) $fishChoices[array_rand($fishChoices)];

    if ($difficulty === 'easy') {
        $target = $others[array_rand($others)];
    } else {
        $handSize = fn($p) => count(j_decode($p['hand']));
        $most = max(array_map($handSize, $others));
        $richest = array_values(array_filter($others, fn($p) => $handSize($p) === $most));
        $target = $richest[array_rand($richest)];
    }

    return [$target, $fish];
}

function bot_player_row(PDO $pdo, int $playerId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM players WHERE id = ?');
    $stmt->execute([$playerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Works out what a bot owes right now: ['ask' | 'respond' | 'guess', bot player row], or null when the
 * move belongs to a human (or nobody). Bots only ever act on their own turn, their own pending
 * go-fish response, or their own tiebreak turn.
 */
function bot_pending(PDO $pdo, array $game): ?array {
    if ($game['status'] === 'playing' && $game['turn_state'] === 'awaiting_ask' && $game['turn_player_id'] !== null) {
        $bot = bot_player_row($pdo, (int) $game['turn_player_id']);
        if ($bot && (int) $bot['is_bot'] === 1) {
            return ['ask', $bot];
        }
    }
    if ($game['status'] === 'playing' && $game['turn_state'] === 'awaiting_gofish' && $game['pending_target_id'] !== null) {
        $bot = bot_player_row($pdo, (int) $game['pending_target_id']);
        if ($bot && (int) $bot['is_bot'] === 1) {
            return ['respond', $bot];
        }
    }
    if ($game['status'] === 'tiebreak') {
        $tiebreakIds = j_decode($game['tiebreak_player_ids']);
        $turnIdx = (int) $game['tiebreak_turn_index'];
        if (isset($tiebreakIds[$turnIdx])) {
            $bot = bot_player_row($pdo, (int) $tiebreakIds[$turnIdx]);
            if ($bot && (int) $bot['is_bot'] === 1) {
                return ['guess', $bot];
            }
        }
    }
    return null;
}

/**
 * Lets any bot whose move is due take it. Called from the polling endpoint, so the whole game is
 * re-read and the scheduled time is claimed before acting: two overlapping polls can't both play
 * the same move.
 *
 * Pacing: the first poll that sees a bot owe a move schedules it for a random time a few seconds
 * away (bot_next_at). A later poll after that time performs it, then clears the schedule so the next
 * move is scheduled afresh. A bot that has nothing legal to do just passes, and the usual turn
 * timeout moves the game on.
 */
function advance_bots(PDO $pdo, array $game): void {
    $owed = bot_pending($pdo, $game);
    if ($owed === null) {
        if ($game['bot_next_at'] !== null) {
            $pdo->prepare('UPDATE games SET bot_next_at = NULL WHERE id = ?')->execute([$game['id']]);
        }
        return;
    }
    [$action, $bot] = $owed;
    $difficulty = $bot['bot_difficulty'] ?: 'medium';

    if ($game['bot_next_at'] === null) {
        $delay = $action === 'respond'
            ? mt_rand(BOT_RESPOND_DELAY[0], BOT_RESPOND_DELAY[1])
            : mt_rand(BOT_ASK_DELAY[$difficulty][0], BOT_ASK_DELAY[$difficulty][1]);
        $pdo->prepare('UPDATE games SET bot_next_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() + $delay), $game['id']]);
        return;
    }
    if (strtotime($game['bot_next_at']) > time()) {
        return;
    }

    // Claim this scheduled move: exactly one request matches the stored value and clears it.
    $claim = $pdo->prepare('UPDATE games SET bot_next_at = NULL WHERE id = ? AND bot_next_at = ?');
    $claim->execute([$game['id'], $game['bot_next_at']]);
    if ($claim->rowCount() === 0) {
        return;
    }

    $pdo->beginTransaction();
    try {
        // Re-read everything under the transaction in case a human acted since the poll began.
        $fresh = fetch_game($pdo, (int) $game['id']);
        $owedNow = bot_pending($pdo, $fresh);
        if ($owedNow !== null) {
            [$action, $bot] = $owedNow;
            if ($action === 'ask') {
                bot_take_turn($pdo, $fresh, $bot);
            } elseif ($action === 'respond') {
                resolve_go_fish($pdo, $fresh);
            } else {
                $activeKeys = active_fish_keys((int) $fresh['fish_set_count']);
                resolve_tiebreak_guess($pdo, $fresh, (int) $bot['id'], $activeKeys[array_rand($activeKeys)]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if (!is_db_busy_error($e)) {
            throw $e;
        }
        // A busy database just means this move waits for the next poll to schedule it again.
    }
}

/** The bot's turn to ask: picks an ask and carries it out through the same code a human ask uses. */
function bot_take_turn(PDO $pdo, array $game, array $bot): void {
    $others = array_values(array_filter(
        playing_players($pdo, (int) $game['id']),
        fn($p) => (int) $p['id'] !== (int) $bot['id']
    ));
    $choice = bot_choose_ask(j_decode($bot['hand']), $others, $bot['bot_difficulty'] ?: 'medium');
    if ($choice === null) {
        return; // nothing legal to ask; the turn timeout will move the game on
    }
    [$target, $fish] = $choice;
    perform_ask($pdo, $game, $bot, $target, $fish);
}
