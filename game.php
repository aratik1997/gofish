<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Go Fish — Pond Party</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎣</text></svg>">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="game-body">

<div class="pond-backdrop"></div>

<header class="game-header">
    <div class="header-left">
        <div class="room-code-badge">Room <strong id="room-code-label">----</strong>
            <button id="copy-room" type="button" title="Copy invite link">📋<span class="btn-label"> Copy Invite</span></button>
        </div>
        <button id="scoreboard-btn" class="icon-btn" type="button" style="display:none" title="Scoreboard">🏆<span class="btn-label"> Scores</span></button>
    </div>
    <div id="turn-banner-wrap" class="turn-banner-wrap">
        <div id="turn-banner" class="turn-banner">Loading pond…</div>
        <div id="turn-timer" class="turn-timer" style="display:none"></div>
    </div>
    <button id="leave-btn" class="icon-btn leave-btn" type="button" title="Leave">🚪<span class="btn-label"> Leave</span></button>
</header>

<div id="spectator-banner" class="spectator-banner" style="display:none">👀 You're spectating — you'll join in the next round</div>

<section id="lobby-overlay" class="overlay">
    <div class="overlay-card">
        <h2>🎣 Waiting for anglers…</h2>
        <p class="lobby-hint">Share the room code <strong id="lobby-room-code">----</strong> with friends (2–6 players).</p>
        <ul id="lobby-players" class="lobby-players"></ul>
        <button id="start-game-btn" class="primary-btn" style="display:none">Start Game</button>
        <p id="lobby-wait-msg" class="lobby-hint">Waiting for the host to start the game…</p>

        <div class="lobby-chat">
            <div id="lobby-chat-log" class="lobby-chat-log"></div>
            <form id="lobby-chat-form" class="lobby-chat-form">
                <input id="lobby-chat-input" type="text" maxlength="200" placeholder="Say hi…" autocomplete="off">
                <button type="submit" class="primary-btn small">Send</button>
            </form>
        </div>

        <button id="lobby-leave-btn" class="secondary-btn" type="button" style="margin-top:14px">Leave Lobby</button>
    </div>
</section>

<main id="game-table" class="game-table" style="display:none">
    <div class="table-ring" id="table-ring">
        <div class="pond" id="pond">
            <div class="pond-water"></div>
            <div class="pond-ripple"></div>
            <div class="deck-pile" id="deck-pile">
                <div class="card-back"></div>
                <div class="card-back card-back-2"></div>
                <div class="deck-count-badge">🐟 <span id="deck-count">0</span></div>
            </div>
            <div id="fishing-line" class="fishing-line" hidden></div>
        </div>
        <div id="seats-layer" class="seats-layer"></div>
    </div>
</main>

<section id="hand-area" class="hand-area" style="display:none">
    <div class="hand-label">
        <span>Your Hand <span id="my-books" class="seat-books my-books"></span></span>
        <span id="ask-hint" class="ask-hint">Tap a card, then tap a player to ask them for it</span>
    </div>
    <div id="hand-cards" class="hand-cards"></div>
</section>

<button id="go-fish-btn" class="go-fish-btn" style="display:none" type="button">
    <span class="go-fish-icon">🐟</span> Go Fish!
</button>

<div id="ask-confirm" class="ask-confirm" style="display:none">
    <span id="ask-confirm-text"></span>
    <button id="ask-confirm-btn" class="primary-btn small">Ask!</button>
    <button id="ask-cancel-btn" class="secondary-btn small">Cancel</button>
</div>

<section id="scoreboard-overlay" class="overlay" style="display:none">
    <div class="overlay-card">
        <h2>🏆 Scoreboard</h2>
        <ol id="scoreboard-list" class="scoreboard-list"></ol>
        <button id="scoreboard-close-btn" class="secondary-btn" type="button">Close</button>
    </div>
</section>

<section id="tiebreak-overlay" class="overlay" style="display:none">
    <div class="overlay-card">
        <h2>🍀 Tiebreaker — Trust Your Luck!</h2>
        <p id="tiebreak-desc" class="lobby-hint"></p>
        <div id="tiebreak-players" class="lobby-players"></div>
        <div id="tiebreak-guess-area">
            <p id="tiebreak-turn-msg" class="lobby-hint"></p>
            <div id="tiebreak-fish-grid" class="fish-grid"></div>
        </div>
        <div id="tiebreak-log" class="tiebreak-log"></div>
    </div>
</section>

<section id="winner-overlay" class="overlay" style="display:none">
    <div class="overlay-card winner-card">
        <h2 id="winner-title">🏆 We have a winner!</h2>
        <div id="winner-name" class="winner-name"></div>
        <div id="winner-books" class="winner-books"></div>
        <div class="winner-actions">
            <button id="play-again-btn" class="primary-btn" type="button" style="display:none">Play Again</button>
            <p id="play-again-wait" class="lobby-hint" style="display:none">Waiting for the host to start a new round…</p>
            <a href="index.php" class="secondary-btn">Leave Room</a>
        </div>
    </div>
</section>

<section id="kicked-overlay" class="overlay" style="display:none">
    <div class="overlay-card">
        <h2 id="kicked-title">You were removed</h2>
        <p id="kicked-msg" class="lobby-hint"></p>
        <a href="index.php" class="primary-btn">Back to Lobby List</a>
    </div>
</section>

<div id="toast" class="toast"></div>
<div id="set-popup" class="set-popup" hidden></div>

<script src="assets/js/app.js"></script>
<script>GoFish.initGame();</script>
</body>
</html>
