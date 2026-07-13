<?php
declare(strict_types=1);

require_once __DIR__ . '/cards.php';
require_once __DIR__ . '/functions.php';

/**
 * Remove completed 4-of-a-kind sets from a hand.
 * Returns [newHand, setsFormed] where setsFormed is a list of fish keys.
 */
function extract_books(array $hand): array {
    $counts = array_count_values($hand);
    $setsFormed = [];
    foreach ($counts as $fish => $count) {
        if ($count >= 4) {
            $setsFormed[] = $fish;
        }
    }
    if (empty($setsFormed)) {
        return [$hand, []];
    }
    $newHand = array_values(array_filter($hand, function ($f) use ($setsFormed) {
        return !in_array($f, $setsFormed, true);
    }));
    return [$newHand, $setsFormed];
}

function next_seat_player(array $players, int $currentPlayerId): array {
    $ids = array_column($players, 'id');
    $idx = array_search($currentPlayerId, $ids, true);
    if ($idx === false) {
        return $players[0];
    }
    $nextIdx = ($idx + 1) % count($players);
    return $players[$nextIdx];
}

function save_player(PDO $pdo, int $playerId, array $hand, array $books): void {
    $stmt = $pdo->prepare('UPDATE players SET hand = ?, books = ? WHERE id = ?');
    $stmt->execute([j_encode($hand), j_encode($books), $playerId]);
}

function push_event(PDO $pdo, int $gameId, array $event): void {
    $stmt = $pdo->prepare('UPDATE games SET last_event = ?, event_seq = event_seq + 1, updated_at = datetime("now") WHERE id = ?');
    $stmt->execute([j_encode($event), $gameId]);
}

function fetch_game(PDO $pdo, int $gameId): array {
    $stmt = $pdo->prepare('SELECT * FROM games WHERE id = ?');
    $stmt->execute([$gameId]);
    return $stmt->fetch();
}

/**
 * A player whose hand is empty draws back up to 5 cards from the pond (or
 * every remaining card, if fewer than 5 are left). Mutates $deck in place.
 * Returns [newHand, newBooks, setsFormed, drawnCount].
 */
function refill_hand_from_deck(array $hand, array $books, array &$deck): array {
    if (count($hand) !== 0 || count($deck) === 0) {
        return [$hand, $books, [], 0];
    }
    $take = min(5, count($deck));
    for ($i = 0; $i < $take; $i++) {
        $hand[] = array_pop($deck);
    }
    [$hand, $setsFormed] = extract_books($hand);
    foreach ($setsFormed as $fish) {
        $books[] = $fish;
    }
    return [$hand, $books, $setsFormed, $take];
}

/**
 * Check whether all 13 sets have been claimed. If so, moves the game to
 * 'finished' (outright winner) or 'tiebreak' (luck round among leaders).
 */
function check_game_end(PDO $pdo, array $game): void {
    $players = playing_players($pdo, (int) $game['id']);
    $totalSets = (int) $game['claimed_sets_by_left'];
    $maxSets = -1;
    $leaders = [];
    foreach ($players as $p) {
        $books = j_decode($p['books']);
        $n = count($books);
        $totalSets += $n;
        if ($n > $maxSets) {
            $maxSets = $n;
            $leaders = [$p];
        } elseif ($n === $maxSets) {
            $leaders[] = $p;
        }
    }

    if ($totalSets < 13 || empty($players)) {
        return;
    }

    if (count($leaders) === 1) {
        $stmt = $pdo->prepare('UPDATE games SET status = ?, winner_player_id = ?, turn_deadline = NULL, updated_at = datetime("now") WHERE id = ?');
        $stmt->execute(['finished', $leaders[0]['id'], $game['id']]);
        push_event($pdo, (int) $game['id'], ['type' => 'game_finished', 'winner_id' => (int) $leaders[0]['id']]);
    } else {
        $leaderIds = array_map(fn($p) => (int) $p['id'], $leaders);
        $stmt = $pdo->prepare('UPDATE games SET status = ?, tiebreak_player_ids = ?, tiebreak_deck = ?, tiebreak_turn_index = 0, turn_deadline = ?, updated_at = datetime("now") WHERE id = ?');
        $stmt->execute(['tiebreak', j_encode($leaderIds), j_encode(build_deck()), new_turn_deadline(), $game['id']]);
        push_event($pdo, (int) $game['id'], ['type' => 'tiebreak_start', 'player_ids' => $leaderIds]);
    }
}

/**
 * Assigns the turn to $playerId. If their hand is empty they refill from
 * the pond (up to 5 cards, or all that remain). If the pond is also empty,
 * they have nothing to play with and are skipped — this repeats around the
 * table until a player who can act is found.
 */
function start_next_turn(PDO $pdo, int $gameId, int $playerId, array $deck): array {
    $players = playing_players($pdo, $gameId);
    if (empty($players)) {
        return $deck;
    }
    $ids = array_column($players, 'id');
    $idx = array_search($playerId, $ids, true);
    if ($idx === false) {
        $idx = 0;
    }

    $candidateId = (int) $ids[$idx];
    for ($i = 0; $i <= count($players); $i++) {
        $tryId = (int) $ids[$idx];
        $stmt = $pdo->prepare('SELECT * FROM players WHERE id = ?');
        $stmt->execute([$tryId]);
        $player = $stmt->fetch();
        $hand = j_decode($player['hand']);
        $books = j_decode($player['books']);

        if (count($hand) === 0) {
            [$hand, $books, $setsFormed, $drawnCount] = refill_hand_from_deck($hand, $books, $deck);
            if ($drawnCount > 0) {
                save_player($pdo, $tryId, $hand, $books);
                if (!empty($setsFormed)) {
                    push_event($pdo, $gameId, ['type' => 'set_completed', 'player_id' => $tryId, 'sets' => $setsFormed, 'via' => 'refill']);
                }
            }
        }

        $candidateId = $tryId;
        if (count($hand) > 0) {
            break; // this player can act — the pond was empty for everyone skipped before them
        }
        $idx = ($idx + 1) % count($players);
    }

    $stmt = $pdo->prepare('UPDATE games SET turn_player_id = ?, turn_state = ?, turn_deadline = ?, pending_asker_id = NULL, pending_target_id = NULL, pending_fish = NULL, deck = ?, updated_at = datetime("now") WHERE id = ?');
    $stmt->execute([$candidateId, 'awaiting_ask', new_turn_deadline(), j_encode($deck), $gameId]);

    return $deck;
}

/**
 * Resolve a pending "go fish" draw for the asker named in $game (shared by
 * the go_fish.php endpoint and by the 60-second timeout auto-resolver).
 */
function resolve_go_fish(PDO $pdo, array $game, bool $timedOut = false): void {
    $askerId = (int) $game['pending_asker_id'];
    $fish = (string) $game['pending_fish'];
    $targetId = (int) $game['pending_target_id'];

    $stmt = $pdo->prepare('SELECT * FROM players WHERE id = ?');
    $stmt->execute([$askerId]);
    $asker = $stmt->fetch();
    if (!$asker) {
        // Asker left mid-exchange; just clear the pending state and move on.
        $players = playing_players($pdo, (int) $game['id']);
        if (!empty($players)) {
            start_next_turn($pdo, (int) $game['id'], (int) $players[0]['id'], j_decode($game['deck']));
        }
        return;
    }

    $deck = j_decode($game['deck']);
    $drawn = null;
    $matched = false;
    $setsFormed = [];
    if (count($deck) > 0) {
        $drawn = array_pop($deck);
        $matched = ($drawn === $fish);

        $askerHand = j_decode($asker['hand']);
        $askerHand[] = $drawn;
        [$askerHand, $setsFormed] = extract_books($askerHand);
        $askerBooks = j_decode($asker['books']);
        foreach ($setsFormed as $s) {
            $askerBooks[] = $s;
        }
        save_player($pdo, $askerId, $askerHand, $askerBooks);
    }

    $stmt = $pdo->prepare('UPDATE games SET deck = ?, updated_at = datetime("now") WHERE id = ?');
    $stmt->execute([j_encode($deck), $game['id']]);

    push_event($pdo, (int) $game['id'], [
        'type' => 'go_fish_result',
        'asker_id' => $askerId,
        'target_id' => $targetId,
        'fish' => $fish,
        'matched' => $matched,
        'sets_formed' => $setsFormed,
        'pond_empty' => count($deck) === 0 && $drawn === null,
        'timed_out' => $timedOut,
    ]);

    $players = playing_players($pdo, (int) $game['id']);
    if (empty($players)) {
        return;
    }
    // Drawing from the pond always ends the turn -- even if the draw matches
    // the asked-for fish or completes a set, the asker does not go again.
    $refIds = array_column($players, 'id');
    $refId = in_array($askerId, $refIds, true) ? $askerId : $players[0]['id'];
    $next = next_seat_player($players, (int) $refId);
    $deck = start_next_turn($pdo, (int) $game['id'], (int) $next['id'], $deck);

    $freshGame = fetch_game($pdo, (int) $game['id']);
    check_game_end($pdo, $freshGame);
}

/**
 * Resolve a tiebreak guess (shared by the tiebreak_guess.php endpoint and
 * the 60-second timeout auto-resolver, which supplies a random guess).
 */
function resolve_tiebreak_guess(PDO $pdo, array $game, int $playerId, string $guess, bool $timedOut = false): void {
    $tiebreakIds = j_decode($game['tiebreak_player_ids']);
    $deck = j_decode($game['tiebreak_deck']);

    if (count($deck) === 0) {
        $deck = build_deck();
    }
    $drawn = array_pop($deck);
    $matched = ($drawn === $guess);

    // Folded into one event (rather than a separate game_finished push) so the
    // winning guess can't get silently overwritten before a client polls it.
    push_event($pdo, (int) $game['id'], [
        'type' => 'tiebreak_guess',
        'player_id' => $playerId,
        'guess' => $guess,
        'drawn' => $drawn,
        'matched' => $matched,
        'timed_out' => $timedOut,
        'winner_id' => $matched ? $playerId : null,
    ]);

    if ($matched) {
        $stmt = $pdo->prepare('UPDATE games SET status = ?, winner_player_id = ?, tiebreak_deck = ?, turn_deadline = NULL, updated_at = datetime("now") WHERE id = ?');
        $stmt->execute(['finished', $playerId, j_encode($deck), $game['id']]);
        return;
    }

    $turnIdx = (int) $game['tiebreak_turn_index'];
    $nextIdx = count($tiebreakIds) > 0 ? ($turnIdx + 1) % count($tiebreakIds) : 0;
    $stmt = $pdo->prepare('UPDATE games SET tiebreak_deck = ?, tiebreak_turn_index = ?, turn_deadline = ?, updated_at = datetime("now") WHERE id = ?');
    $stmt->execute([j_encode($deck), $nextIdx, new_turn_deadline(), $game['id']]);
}

/**
 * Applies the 60-second turn/response/guess timeout if it has passed.
 * Safe to call on every poll: the UPDATE's WHERE clause only lets one
 * concurrent request actually perform the resolution.
 */
function check_and_apply_timeout(PDO $pdo, array $game): array {
    if (!in_array($game['status'], ['playing', 'tiebreak'], true)) {
        return $game;
    }
    if (empty($game['turn_deadline'])) {
        return $game;
    }
    if (strtotime($game['turn_deadline']) > time()) {
        return $game;
    }

    // Claim the timeout: only one concurrent request will affect a row here.
    $stmt = $pdo->prepare('UPDATE games SET turn_deadline = NULL WHERE id = ? AND turn_deadline = ?');
    $stmt->execute([$game['id'], $game['turn_deadline']]);
    if ($stmt->rowCount() === 0) {
        return fetch_game($pdo, (int) $game['id']);
    }

    if ($game['status'] === 'tiebreak') {
        $tiebreakIds = j_decode($game['tiebreak_player_ids']);
        $turnIdx = (int) $game['tiebreak_turn_index'];
        if (isset($tiebreakIds[$turnIdx])) {
            $playerId = (int) $tiebreakIds[$turnIdx];
            $randomGuess = fish_keys()[array_rand(fish_keys())];
            resolve_tiebreak_guess($pdo, $game, $playerId, $randomGuess, true);
        }
    } elseif ($game['turn_state'] === 'awaiting_gofish') {
        resolve_go_fish($pdo, $game, true);
    } elseif ($game['turn_state'] === 'awaiting_ask') {
        $players = playing_players($pdo, (int) $game['id']);
        if (!empty($players)) {
            $refIds = array_column($players, 'id');
            $currentId = (int) $game['turn_player_id'];
            $refId = in_array($currentId, $refIds, true) ? $currentId : $players[0]['id'];
            $next = next_seat_player($players, (int) $refId);
            push_event($pdo, (int) $game['id'], ['type' => 'turn_timeout', 'player_id' => $currentId]);
            start_next_turn($pdo, (int) $game['id'], (int) $next['id'], j_decode($game['deck']));
            $freshGame = fetch_game($pdo, (int) $game['id']);
            check_game_end($pdo, $freshGame);
        }
    }

    return fetch_game($pdo, (int) $game['id']);
}

/**
 * Removes a player from the game (voluntary leave or host kick):
 *  - their hand cards are shuffled back into the pond deck
 *  - their claimed sets are permanently banked out of play
 *  - if they held the turn (or were mid ask/go-fish), the turn advances
 *  - if they were the host, a random remaining player becomes host
 */
function remove_player_from_game(PDO $pdo, array $game, array $target, string $reason, ?string $byName): void {
    $gameId = (int) $game['id'];
    $targetId = (int) $target['id'];

    if (in_array($game['status'], ['playing', 'tiebreak'], true) && !$target['is_spectator']) {
        $deck = j_decode($game['deck']);
        $hand = j_decode($target['hand']);
        foreach ($hand as $card) {
            $deck[] = $card;
        }
        shuffle($deck);
        $books = j_decode($target['books']);
        $stmt = $pdo->prepare('UPDATE games SET deck = ?, claimed_sets_by_left = claimed_sets_by_left + ? WHERE id = ?');
        $stmt->execute([j_encode($deck), count($books), $gameId]);
        $game = fetch_game($pdo, $gameId);
    }

    $stmt = $pdo->prepare('UPDATE players SET status = ?, left_by = ?, hand = "[]", books = "[]" WHERE id = ?');
    $stmt->execute([$reason, $byName, $targetId]);

    $newHostName = null;
    if ($target['is_host']) {
        $stmt = $pdo->prepare('SELECT * FROM players WHERE game_id = ? AND status = "active" AND id != ? ORDER BY RANDOM() LIMIT 1');
        $stmt->execute([$gameId, $targetId]);
        $newHost = $stmt->fetch();
        if ($newHost) {
            $pdo->prepare('UPDATE players SET is_host = 1 WHERE id = ?')->execute([$newHost['id']]);
            $newHostName = $newHost['name'];
        }
    }

    // A single combined event so a quick host-reassignment can't get silently
    // overwritten by the leave/kick event before any client polls in between.
    push_event($pdo, $gameId, [
        'type' => $reason === 'kicked' ? 'player_kicked' : 'player_left',
        'name' => $target['name'],
        'by' => $byName,
        'new_host' => $newHostName,
    ]);

    if (!in_array($game['status'], ['playing', 'tiebreak'], true) || $target['is_spectator']) {
        return;
    }

    if ($game['status'] === 'tiebreak') {
        $tiebreakIds = array_values(array_filter(j_decode($game['tiebreak_player_ids']), fn($id) => (int) $id !== $targetId));
        if (count($tiebreakIds) <= 1) {
            $winnerId = $tiebreakIds[0] ?? null;
            $stmt = $pdo->prepare('UPDATE games SET status = ?, winner_player_id = ?, turn_deadline = NULL, updated_at = datetime("now") WHERE id = ?');
            $stmt->execute(['finished', $winnerId, $gameId]);
            if ($winnerId) {
                push_event($pdo, $gameId, ['type' => 'game_finished', 'winner_id' => (int) $winnerId]);
            }
        } else {
            $turnIdx = (int) $game['tiebreak_turn_index'] % count($tiebreakIds);
            $stmt = $pdo->prepare('UPDATE games SET tiebreak_player_ids = ?, tiebreak_turn_index = ? WHERE id = ?');
            $stmt->execute([j_encode($tiebreakIds), $turnIdx, $gameId]);
        }
        return;
    }

    // status === 'playing'
    $remaining = playing_players($pdo, $gameId);
    if (count($remaining) < 2) {
        $winner = $remaining[0] ?? null;
        $stmt = $pdo->prepare('UPDATE games SET status = ?, winner_player_id = ?, turn_deadline = NULL, updated_at = datetime("now") WHERE id = ?');
        $stmt->execute(['finished', $winner ? $winner['id'] : null, $gameId]);
        if ($winner) {
            push_event($pdo, $gameId, ['type' => 'game_finished', 'winner_id' => (int) $winner['id']]);
        }
        return;
    }

    $wasTurnHolder = (int) $game['turn_player_id'] === $targetId;
    $wasPending = (int) $game['pending_asker_id'] === $targetId || (int) $game['pending_target_id'] === $targetId;

    if ($wasTurnHolder || $wasPending) {
        // Whoever held the turn (directly or via a voided ask) — advance from their old seat.
        $refSeat = (int) $target['seat_order'];
        $next = $remaining[0];
        foreach ($remaining as $p) {
            if ((int) $p['seat_order'] > $refSeat) {
                $next = $p;
                break;
            }
        }
        $deck = j_decode(fetch_game($pdo, $gameId)['deck']);
        start_next_turn($pdo, $gameId, (int) $next['id'], $deck);
        $freshGame = fetch_game($pdo, $gameId);
        check_game_end($pdo, $freshGame);
    }
}
