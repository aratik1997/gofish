# 🎣 Go Fish — Pond Party

A real-time, online multiplayer Go Fish card game for 2–10 players, playable across separate devices in a browser. Players sit around a pond, ask each other for cards, and "fish" from the deck when they come up empty.

No build step, no framework, no WebSocket server — just PHP, SQLite, and a polling-based sync loop.

## Features

- **2–10 player online lobbies** with shareable room codes
- 13 fish-themed sets (shrimp, whale, turtle, clownfish, ...), each worth a "book" when a player collects all 4 of a kind — games with 7+ players add 2 extra sets per player above 6 (up to 21 sets) so there are enough cards to go around
- Classic Go Fish flow: ask a player for a card you hold, they hand over all matching cards or send you fishing from the pond
- **60-second turn timer** with automatic skip/resolution on timeout
- Empty-handed players automatically redraw up to 5 cards from the pond, draw fewer if the pond is low, or get skipped if it's empty
- **Luck-based tiebreaker**: one card is drawn and kept hidden; tied leaders take turns guessing it, first correct guess wins
- Host controls: kick players, mid-lobby chat, automatic host reassignment if the host leaves
- Mid-game joiners spectate until the round ends, then join the next one
- Live scoreboard, sorted by book count
- Sound effects (splash, catch, set-complete) synthesized in-browser via the Web Audio API — no audio files
- Fully responsive layout for mobile, tablet, and desktop
- Pond/table theme with animated fishing line and set-completion pop-ups

## Tech stack

- **Backend**: PHP 8.1+, PDO SQLite (no framework)
- **Frontend**: Vanilla JS, HTML, CSS (no build tooling)
- **Sync**: Client-side polling (1.5s interval) against a JSON state endpoint
- **Storage**: SQLite file, schema auto-created on first request

## Getting started

### Requirements

- PHP 8.1+ with the `pdo_sqlite` and `sqlite3` extensions enabled
- Apache (e.g. via XAMPP) or PHP's built-in server

### Run with XAMPP

1. Place this project in `htdocs/gofish` (or clone it there).
2. Make sure `pdo_sqlite` and `sqlite3` are uncommented in `php.ini`.
3. Start Apache and visit `http://localhost/gofish/index.php`.

### Run with PHP's built-in server

```bash
php -S 127.0.0.1:8099 -t .
```

Then open `http://127.0.0.1:8099/index.php`. The SQLite database (`data/gofish.sqlite`) is created automatically from `sql/schema.sql` on first request.

## How to play

1. One player creates a game and shares the room code.
2. 2–10 players join the lobby; the host starts the game once everyone's in.
3. Each player starts with 5 cards. On your turn, ask any other player for a fish type you already hold at least one of.
4. If they have it, they hand over every matching card. If not, you "Go Fish" — draw a card from the pond.
5. Collecting all 4 of a fish type auto-extracts it as a scored book next to your name.
6. When every set in play is claimed, the player with the most books wins. Ties are settled in a luck-based guessing round.

## Project structure

```
api/          JSON endpoints (create/join/leave/kick, ask, go-fish, tiebreak, chat, state polling)
includes/     Core game logic, DB connection, card/deck helpers, shared functions
assets/       CSS, JS, and card art
sql/          Database schema
data/         SQLite database (generated, gitignored)
index.php     Landing page (create/join)
game.php      Game table screen
```
