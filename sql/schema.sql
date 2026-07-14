-- Go Fish game schema (SQLite)

CREATE TABLE IF NOT EXISTS games (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_code TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'waiting',   -- waiting | playing | tiebreak | finished
    deck TEXT NOT NULL DEFAULT '[]',           -- JSON array of fish-type strings (pond deck, top = end of array)
    turn_player_id INTEGER,
    turn_state TEXT NOT NULL DEFAULT 'awaiting_ask', -- awaiting_ask | awaiting_gofish
    turn_deadline TEXT,                        -- datetime: current turn/response/guess must resolve by this time
    pending_asker_id INTEGER,
    pending_target_id INTEGER,
    pending_fish TEXT,
    last_event TEXT NOT NULL DEFAULT '{}',     -- JSON describing the most recent action, for toast/animation
    event_seq INTEGER NOT NULL DEFAULT 0,
    tiebreak_player_ids TEXT NOT NULL DEFAULT '[]', -- JSON array of player ids still competing
    tiebreak_deck TEXT NOT NULL DEFAULT '[]',
    tiebreak_turn_index INTEGER NOT NULL DEFAULT 0,
    claimed_sets_by_left INTEGER NOT NULL DEFAULT 0, -- sets banked permanently when their owner left/was kicked
    winner_player_id INTEGER,
    fish_set_count INTEGER NOT NULL DEFAULT 13, -- how many fish types are in play this round (13 base + 2 per player above 6)
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    game_id INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    token TEXT NOT NULL UNIQUE,
    seat_order INTEGER NOT NULL,
    is_host INTEGER NOT NULL DEFAULT 0,
    is_spectator INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'active',  -- active | left | kicked
    left_by TEXT,                            -- name of the host who kicked them (null for voluntary leave)
    hand TEXT NOT NULL DEFAULT '[]',   -- JSON array of fish-type strings
    books TEXT NOT NULL DEFAULT '[]',  -- JSON array of fish-type strings (completed sets, one entry per set)
    connected INTEGER NOT NULL DEFAULT 1,
    last_seen TEXT NOT NULL DEFAULT (datetime('now')),
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_players_game ON players(game_id);

CREATE TABLE IF NOT EXISTS chat_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    game_id INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
    player_id INTEGER,
    name TEXT NOT NULL,
    message TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_chat_game ON chat_messages(game_id, id);
