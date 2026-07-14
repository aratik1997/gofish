<?php
declare(strict_types=1);

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function json_error(string $message, int $status = 400): void {
    json_out(['ok' => false, 'error' => $message], $status);
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function gen_room_code(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I ambiguity
    $code = '';
    for ($i = 0; $i < 5; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

function gen_token(): string {
    return bin2hex(random_bytes(24));
}

/** Fetch the game row for a room code, or send a 404 error. */
function require_game(PDO $pdo, string $roomCode): array {
    $stmt = $pdo->prepare('SELECT * FROM games WHERE room_code = ?');
    $stmt->execute([strtoupper(trim($roomCode))]);
    $game = $stmt->fetch();
    if (!$game) {
        json_error('Game not found', 404);
    }
    return $game;
}

/** Fetch the player row for a token within a game, or send a 401 error. */
function require_player(PDO $pdo, int $gameId, string $token): array {
    $stmt = $pdo->prepare('SELECT * FROM players WHERE game_id = ? AND token = ?');
    $stmt->execute([$gameId, $token]);
    $player = $stmt->fetch();
    if (!$player) {
        json_error('Player not found', 401);
    }
    return $player;
}

/** Players currently present in the room (includes spectators, excludes those who left/were kicked). */
function all_players(PDO $pdo, int $gameId): array {
    $stmt = $pdo->prepare('SELECT * FROM players WHERE game_id = ? AND status = "active" ORDER BY seat_order ASC');
    $stmt->execute([$gameId]);
    return $stmt->fetchAll();
}

/** Players actually dealt into the current/next hand — present and not spectating. */
function playing_players(PDO $pdo, int $gameId): array {
    $stmt = $pdo->prepare('SELECT * FROM players WHERE game_id = ? AND status = "active" AND is_spectator = 0 ORDER BY seat_order ASC');
    $stmt->execute([$gameId]);
    return $stmt->fetchAll();
}

const TURN_TIMEOUT_SECONDS = 60;
const MAX_PLAYERS = 10;

function new_turn_deadline(): string {
    return date('Y-m-d H:i:s', time() + TURN_TIMEOUT_SECONDS);
}

function j_decode(string $json): array {
    $v = json_decode($json, true);
    return is_array($v) ? $v : [];
}

function j_encode(array $data): string {
    return json_encode($data);
}

/**
 * True if $e represents a transient SQLite lock/busy contention error
 * (as opposed to a real bug) -- these are safe to retry.
 */
function is_db_busy_error(Throwable $e): bool {
    $msg = $e->getMessage();
    return stripos($msg, 'locked') !== false || stripos($msg, 'busy') !== false;
}

/** Short randomized pause between retry attempts, growing with each attempt. */
function db_retry_backoff(int $attempt): void {
    usleep(random_int(40000, 120000) * $attempt);
}
