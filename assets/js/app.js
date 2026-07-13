(function () {
    'use strict';

    var API = 'api/';
    var POLL_MS = 1500;

    /**
     * Real fish PNGs can be dropped into assets/img/cards/<key>.png.
     * If a key is listed here (or a file matching the pattern is found),
     * app.js will use the image instead of the emoji placeholder.
     * Simplest path: just add e.g. { shrimp: 'assets/img/cards/shrimp.png' }.
     */
    var CARD_IMAGE_OVERRIDE = {};

    function qs(id) { return document.getElementById(id); }
    function storageKey(room) { return 'gofish:' + room.toUpperCase(); }

    function saveSession(room, data) {
        localStorage.setItem(storageKey(room), JSON.stringify(data));
    }
    function loadSession(room) {
        try {
            var raw = localStorage.getItem(storageKey(room));
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }
    function clearSession(room) {
        localStorage.removeItem(storageKey(room));
    }

    function post(url, body) {
        return fetch(API + url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().then(function (data) {
                if (!r.ok || data.ok === false) {
                    throw new Error(data.error || 'Request failed');
                }
                return data;
            });
        });
    }

    function get(url) {
        return fetch(API + url).then(function (r) {
            return r.json().then(function (data) {
                if (!r.ok || data.ok === false) {
                    throw new Error(data.error || 'Request failed');
                }
                return data;
            });
        });
    }

    // ================= Sound (Web Audio splash synth — no asset files needed) =================

    var audioCtx = null;
    function primeAudio() {
        if (audioCtx) return;
        try {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        } catch (e) { /* Web Audio unavailable — sound is a nice-to-have, not required */ }
    }
    function playSplash() {
        if (!audioCtx) return;
        if (audioCtx.state === 'suspended') audioCtx.resume();
        var ctx = audioCtx;
        var duration = 0.45;
        var bufferSize = Math.floor(ctx.sampleRate * duration);
        var buffer = ctx.createBuffer(1, bufferSize, ctx.sampleRate);
        var data = buffer.getChannelData(0);
        for (var i = 0; i < bufferSize; i++) {
            data[i] = Math.random() * 2 - 1;
        }
        var noise = ctx.createBufferSource();
        noise.buffer = buffer;

        var filter = ctx.createBiquadFilter();
        filter.type = 'bandpass';
        filter.frequency.setValueAtTime(2000, ctx.currentTime);
        filter.frequency.exponentialRampToValueAtTime(250, ctx.currentTime + duration);
        filter.Q.value = 0.6;

        var gain = ctx.createGain();
        gain.gain.setValueAtTime(0.0001, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.5, ctx.currentTime + 0.03);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + duration);

        noise.connect(filter);
        filter.connect(gain);
        gain.connect(ctx.destination);
        noise.start();
        noise.stop(ctx.currentTime + duration);
    }

    function playCatchSound() {
        if (!audioCtx) return;
        if (audioCtx.state === 'suspended') audioCtx.resume();
        var ctx = audioCtx;
        var osc = ctx.createOscillator();
        osc.type = 'triangle';
        osc.frequency.setValueAtTime(500, ctx.currentTime);
        osc.frequency.exponentialRampToValueAtTime(1100, ctx.currentTime + 0.16);
        var gain = ctx.createGain();
        gain.gain.setValueAtTime(0.0001, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.35, ctx.currentTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.22);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.24);
    }

    function playSetCompleteSound() {
        if (!audioCtx) return;
        if (audioCtx.state === 'suspended') audioCtx.resume();
        var ctx = audioCtx;
        var notes = [523.25, 659.25, 783.99, 1046.5]; // C5 E5 G5 C6 — little ascending fanfare
        notes.forEach(function (freq, i) {
            var start = ctx.currentTime + i * 0.09;
            var osc = ctx.createOscillator();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, start);
            var gain = ctx.createGain();
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(0.3, start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.3);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(start);
            osc.stop(start + 0.32);
        });
    }

    // ================= Landing page =================

    function initLanding() {
        var params = new URLSearchParams(window.location.search);
        var prefRoom = params.get('room');

        var tabBtns = document.querySelectorAll('.tab-btn');
        tabBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                tabBtns.forEach(function (b) { b.classList.remove('active'); });
                document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('active'); });
                btn.classList.add('active');
                qs(btn.dataset.tab + '-form').classList.add('active');
            });
        });

        if (prefRoom) {
            qs('join-code').value = prefRoom.toUpperCase();
            document.querySelector('[data-tab="join"]').click();
        }

        qs('create-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var name = qs('create-name').value.trim();
            qs('create-error').textContent = '';
            if (!name) return;
            post('create_game.php', { name: name }).then(function (data) {
                saveSession(data.room_code, { token: data.token, playerId: data.player_id, name: name });
                window.location.href = 'game.php?room=' + encodeURIComponent(data.room_code);
            }).catch(function (err) {
                qs('create-error').textContent = err.message;
            });
        });

        qs('join-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var name = qs('join-name').value.trim();
            var room = qs('join-code').value.trim().toUpperCase();
            qs('join-error').textContent = '';
            if (!name || !room) return;
            post('join_game.php', { name: name, room_code: room }).then(function (data) {
                saveSession(data.room_code, { token: data.token, playerId: data.player_id, name: name });
                window.location.href = 'game.php?room=' + encodeURIComponent(data.room_code);
            }).catch(function (err) {
                qs('join-error').textContent = err.message;
            });
        });
    }

    // ================= Game page =================

    var state = {
        room: null,
        session: null,
        selectedFish: null,
        selectedTarget: null,
        lastSeenEventSeq: -1,
        fishTypes: {},
        pollTimer: null,
        polling: false,
        stopped: false,
        clockSkewSec: 0,
        turnDeadlineTs: null
    };

    function initGame() {
        var params = new URLSearchParams(window.location.search);
        var room = (params.get('room') || '').toUpperCase();
        if (!room) { window.location.href = 'index.php'; return; }

        var session = loadSession(room);
        if (!session) {
            window.location.href = 'index.php?room=' + encodeURIComponent(room);
            return;
        }

        state.room = room;
        state.session = session;

        qs('room-code-label').textContent = room;
        qs('lobby-room-code').textContent = room;

        var primeAudioOnce = function () { primeAudio(); document.removeEventListener('click', primeAudioOnce); document.removeEventListener('touchstart', primeAudioOnce); };
        document.addEventListener('click', primeAudioOnce);
        document.addEventListener('touchstart', primeAudioOnce);

        qs('copy-room').addEventListener('click', function () {
            var url = window.location.origin + window.location.pathname.replace(/game\.php.*/, '') + 'index.php?room=' + room;
            navigator.clipboard.writeText(url).then(function () {
                qs('copy-room').textContent = 'Copied!';
                setTimeout(function () { qs('copy-room').textContent = 'Copy Invite'; }, 1500);
            }).catch(function () {});
        });

        qs('start-game-btn').addEventListener('click', function () {
            post('start_game.php', { room_code: state.room, token: state.session.token })
                .then(poll)
                .catch(function (err) { alert(err.message); });
        });

        qs('go-fish-btn').addEventListener('click', function () {
            qs('go-fish-btn').disabled = true;
            post('go_fish.php', { room_code: state.room, token: state.session.token })
                .then(poll)
                .catch(function (err) { alert(err.message); })
                .finally(function () { qs('go-fish-btn').disabled = false; });
        });

        qs('ask-confirm-btn').addEventListener('click', submitAsk);
        qs('ask-cancel-btn').addEventListener('click', clearAskSelection);

        qs('leave-btn').addEventListener('click', doLeave);
        qs('lobby-leave-btn').addEventListener('click', doLeave);

        qs('scoreboard-btn').addEventListener('click', function () { qs('scoreboard-overlay').style.display = 'flex'; });
        qs('scoreboard-close-btn').addEventListener('click', function () { qs('scoreboard-overlay').style.display = 'none'; });

        qs('play-again-btn').addEventListener('click', function () {
            qs('play-again-btn').disabled = true;
            post('play_again.php', { room_code: state.room, token: state.session.token })
                .then(poll)
                .catch(function (err) { alert(err.message); })
                .finally(function () { qs('play-again-btn').disabled = false; });
        });

        qs('lobby-chat-form').addEventListener('submit', function (e) {
            e.preventDefault();
            var input = qs('lobby-chat-input');
            var msg = input.value.trim();
            if (!msg) return;
            input.value = '';
            post('chat_send.php', { room_code: state.room, token: state.session.token, message: msg })
                .then(poll)
                .catch(function (err) { alert(err.message); });
        });

        poll();
        state.pollTimer = setInterval(poll, POLL_MS);
        setInterval(tickTurnTimer, 1000);

        window.addEventListener('resize', function () { requestAnimationFrame(updateLayoutVars); });
        window.addEventListener('orientationchange', function () { requestAnimationFrame(updateLayoutVars); });
    }

    function doLeave() {
        if (!confirm('Leave the pond? Any cards in your hand will be shuffled back into the deck.')) return;
        post('leave_game.php', { room_code: state.room, token: state.session.token })
            .catch(function () {})
            .finally(function () {
                state.stopped = true;
                clearInterval(state.pollTimer);
                clearSession(state.room);
                window.location.href = 'index.php';
            });
    }

    function doKick(player) {
        if (!confirm('Kick ' + player.name + ' from the game?')) return;
        post('kick_player.php', { room_code: state.room, token: state.session.token, target_id: player.id })
            .then(poll)
            .catch(function (err) { alert(err.message); });
    }

    function poll() {
        if (state.polling || state.stopped) return Promise.resolve();
        state.polling = true;
        return get('state.php?room_code=' + encodeURIComponent(state.room) + '&token=' + encodeURIComponent(state.session.token))
            .then(render)
            .catch(function (err) {
                console.error(err);
            })
            .finally(function () { state.polling = false; });
    }

    // ---------- Rendering ----------

    function render(data) {
        if (data.removed) {
            handleRemoved(data);
            return;
        }

        state.fishTypes = data.fish_types;
        state.clockSkewSec = data.server_time - Math.floor(Date.now() / 1000);
        var game = data.game;
        var players = data.players;
        var me = data.me;

        handleEvent(game, players);
        requestAnimationFrame(updateLayoutVars);

        qs('leave-btn').style.display = 'inline-block';
        qs('scoreboard-btn').style.display = (game.status === 'waiting') ? 'none' : 'inline-block';
        qs('turn-banner-wrap').style.display = (game.status === 'waiting') ? 'none' : 'flex';
        state.turnDeadlineTs = (game.status === 'playing' || game.status === 'tiebreak') ? game.turn_deadline_ts : null;
        tickTurnTimer();

        if (game.status === 'waiting') {
            showOnly('lobby');
            qs('spectator-banner').style.display = 'none';
            renderLobby(game, players, me);
            renderChat(data.chat);
            return;
        }

        showOnly(game.status === 'tiebreak' ? 'tiebreak' : (game.status === 'finished' ? 'finished' : 'playing'));
        renderScoreboard(players);

        var spectating = me.is_spectator && game.status !== 'finished';
        qs('spectator-banner').style.display = spectating ? 'block' : 'none';
        qs('hand-area').style.display = (game.status === 'playing' && !me.is_spectator) ? 'block' : 'none';

        if (game.status === 'playing') {
            renderTable(game, players, me);
            if (!me.is_spectator) {
                renderHand(game, players, me);
            } else {
                qs('go-fish-btn').style.display = 'none';
            }
            renderTurnBanner(game, players, me);
        } else if (game.status === 'tiebreak') {
            renderTable(game, players, me);
            renderTiebreak(game, players, me);
            qs('turn-banner').textContent = '🍀 Tiebreaker in progress…';
        } else if (game.status === 'finished') {
            renderTable(game, players, me);
            renderWinner(game, players, me);
            qs('turn-banner').textContent = '🏁 Game over';
        }
    }

    function handleRemoved(data) {
        state.stopped = true;
        clearInterval(state.pollTimer);
        clearSession(state.room);
        if (data.reason === 'kicked') {
            showOnly('none');
            qs('kicked-title').textContent = 'You were kicked';
            qs('kicked-msg').textContent = (data.by || 'The host') + ' Kicked you.';
            qs('kicked-overlay').style.display = 'flex';
        } else {
            window.location.href = 'index.php';
        }
    }

    function showOnly(mode) {
        qs('lobby-overlay').style.display = mode === 'lobby' ? 'flex' : 'none';
        qs('game-table').style.display = (mode === 'playing' || mode === 'tiebreak' || mode === 'finished') ? 'flex' : 'none';
        qs('tiebreak-overlay').style.display = mode === 'tiebreak' ? 'flex' : 'none';
        qs('winner-overlay').style.display = mode === 'finished' ? 'flex' : 'none';
        qs('kicked-overlay').style.display = 'none';
        if (mode !== 'playing') {
            qs('hand-area').style.display = 'none';
            qs('go-fish-btn').style.display = 'none';
            qs('ask-confirm').style.display = 'none';
        }
        if (mode !== 'lobby') {
            // handled per-status below in render(); nothing extra here
        }
    }

    function renderLobby(game, players, me) {
        var list = qs('lobby-players');
        list.innerHTML = '';
        players.forEach(function (p) {
            var li = document.createElement('li');
            var left = document.createElement('span');
            left.className = 'li-left';
            left.innerHTML = '<span>' + escapeHtml(p.name) + (p.id === me.id ? ' (you)' : '') + '</span>' +
                (p.is_host ? '<span class="host-tag">HOST</span>' : '') +
                (p.is_spectator ? '<span class="spectator-tag">👀 SPECTATING</span>' : '');
            li.appendChild(left);
            if (me.is_host && p.id !== me.id) {
                var kickBtn = document.createElement('button');
                kickBtn.type = 'button';
                kickBtn.className = 'kick-btn';
                kickBtn.textContent = 'Kick';
                kickBtn.addEventListener('click', function () { doKick(p); });
                li.appendChild(kickBtn);
            }
            list.appendChild(li);
        });
        var startBtn = qs('start-game-btn');
        var waitMsg = qs('lobby-wait-msg');
        var playing = players.filter(function (p) { return !p.is_spectator; });
        if (me.is_host) {
            startBtn.style.display = 'inline-block';
            startBtn.disabled = playing.length < 2;
            startBtn.textContent = playing.length < 2 ? 'Need at least 2 players' : 'Start Game (' + playing.length + ' players)';
            waitMsg.style.display = 'none';
        } else {
            startBtn.style.display = 'none';
            waitMsg.style.display = 'block';
        }
    }

    function renderChat(chat) {
        var log = qs('lobby-chat-log');
        log.innerHTML = (chat || []).map(function (m) {
            return '<div class="chat-msg"><strong>' + escapeHtml(m.name) + ':</strong>' + escapeHtml(m.message) + '</div>';
        }).join('');
        log.scrollTop = log.scrollHeight;
    }

    function seatEmoji(name) {
        return (name || '?').trim().charAt(0).toUpperCase();
    }

    function renderTable(game, players, me) {
        qs('deck-count').textContent = game.deck_count;

        var meIndex = players.findIndex(function (p) { return p.id === me.id; });
        if (meIndex === -1) meIndex = 0;
        var ordered = players.slice(meIndex).concat(players.slice(0, meIndex));

        var layer = qs('seats-layer');
        layer.innerHTML = '';
        var n = ordered.length;
        var R = 42; // percent radius from center

        ordered.forEach(function (p, i) {
            // Skip a seat for "me" — my own hand/status is already shown in the hand area below.
            // The angle math still accounts for me so the gap at the bottom stays natural.
            if (p.id === me.id) return;

            var angle = (Math.PI / 2) + (i * (2 * Math.PI / n)); // start at bottom, go around
            var x = 50 + R * Math.cos(angle);
            var y = 50 + R * Math.sin(angle);

            var seat = document.createElement('div');
            seat.className = 'seat';
            if (game.turn_player_id === p.id && game.status === 'playing') seat.classList.add('current-turn');
            if (!p.connected) seat.classList.add('disconnected');

            var isAskable = game.status === 'playing' && game.turn_state === 'awaiting_ask' &&
                game.turn_player_id === me.id && !p.is_spectator && state.selectedFish;
            if (isAskable) {
                seat.classList.add('askable');
                seat.addEventListener('click', function () { selectTarget(p); });
            }

            seat.style.left = x + '%';
            seat.style.top = y + '%';

            var statusHtml = p.is_spectator
                ? '<div class="seat-hand-count">👀 Spectating</div><div class="seat-books"></div>'
                : ('<div class="seat-hand-count">🃏 ' + p.hand_count + '</div><div class="seat-books">' +
                    (p.books || []).map(function (fish) {
                        var ft = state.fishTypes[fish];
                        return '<div class="book-chip" title="' + (ft ? ft.name : fish) + '">' + (ft ? ft.emoji : '🐟') + '</div>';
                    }).join('') + '</div>');

            seat.innerHTML =
                '<div class="seat-avatar">' + seatEmoji(p.name) + '</div>' +
                '<div class="seat-name">' + escapeHtml(p.name) + (p.is_host ? ' 👑' : '') + '</div>' +
                statusHtml;

            if (me.is_host && p.id !== me.id) {
                var kickBtn = document.createElement('button');
                kickBtn.type = 'button';
                kickBtn.className = 'seat-kick-btn';
                kickBtn.textContent = '✕';
                kickBtn.title = 'Kick ' + p.name;
                kickBtn.addEventListener('click', function (e) { e.stopPropagation(); doKick(p); });
                seat.querySelector('.seat-avatar').appendChild(kickBtn);
            }

            layer.appendChild(seat);
        });
    }

    function renderHand(game, players, me) {
        var wrap = qs('hand-cards');
        wrap.innerHTML = '';
        var myTurn = game.status === 'playing' && game.turn_state === 'awaiting_ask' && game.turn_player_id === me.id;

        qs('ask-hint').style.display = myTurn ? 'block' : 'none';

        var myBooksWrap = qs('my-books');
        myBooksWrap.innerHTML = (me.books || []).map(function (fish) {
            var ft = state.fishTypes[fish];
            return '<div class="book-chip" title="' + (ft ? ft.name : fish) + '">' + (ft ? ft.emoji : '🐟') + '</div>';
        }).join('');

        var sortedHand = sortHandSetWise(me.hand);
        sortedHand.forEach(function (fish, idx) {
            var ft = state.fishTypes[fish];
            var card = document.createElement('div');
            card.className = 'play-card';
            if (!myTurn) card.classList.add('disabled-ask');
            if (state.selectedFish === fish) card.classList.add('selected');
            card.innerHTML =
                '<div class="pc-number">' + (ft ? ft.number : '') + '</div>' +
                '<div class="pc-emoji">' + cardArt(fish) + '</div>' +
                '<div class="pc-name">' + (ft ? ft.name : fish) + '</div>';
            if (myTurn) {
                card.addEventListener('click', function () { selectFish(fish); });
            }
            wrap.appendChild(card);
        });

        // Go Fish button: shown to the player who was just asked and doesn't have the card.
        var iAmPendingTarget = game.status === 'playing' && game.turn_state === 'awaiting_gofish' && game.pending_target_id === me.id;
        qs('go-fish-btn').style.display = iAmPendingTarget ? 'flex' : 'none';

        if (!myTurn || game.turn_state !== 'awaiting_ask') {
            clearAskSelection();
        }
    }

    function sortHandSetWise(hand) {
        var counts = {};
        hand.forEach(function (f) { counts[f] = (counts[f] || 0) + 1; });
        return hand.slice().sort(function (a, b) {
            if (counts[a] !== counts[b]) return counts[b] - counts[a]; // bigger groups (closer to a set) first
            var na = state.fishTypes[a] ? state.fishTypes[a].number : 0;
            var nb = state.fishTypes[b] ? state.fishTypes[b].number : 0;
            if (na !== nb) return na - nb;
            return 0;
        });
    }

    function cardArt(fishKey) {
        if (CARD_IMAGE_OVERRIDE[fishKey]) {
            return '<img src="' + CARD_IMAGE_OVERRIDE[fishKey] + '" alt="" style="width:34px;height:34px;object-fit:contain;">';
        }
        var ft = state.fishTypes[fishKey];
        return ft ? ft.emoji : '🐟';
    }

    function renderTurnBanner(game, players, me) {
        var banner = qs('turn-banner');
        banner.classList.remove('mine');

        if (me.is_spectator) {
            banner.textContent = '👀 Spectating the pond…';
            return;
        }

        if (game.turn_state === 'awaiting_gofish') {
            var target = players.find(function (p) { return p.id === game.pending_target_id; });
            var asker = players.find(function (p) { return p.id === game.pending_asker_id; });
            var ft = state.fishTypes[game.pending_fish];
            if (game.pending_target_id === me.id) {
                banner.textContent = (asker ? asker.name : '?') + ' asked you for ' + (ft ? ft.name : '') + ' ' + (ft ? ft.emoji : '') + ' — press Go Fish!';
                banner.classList.add('mine');
            } else if (game.pending_asker_id === me.id) {
                banner.textContent = 'Waiting for ' + (target ? target.name : '?') + ' to respond…';
            } else {
                banner.textContent = (asker ? asker.name : '?') + ' asked ' + (target ? target.name : '?') + ' for ' + (ft ? ft.name : '');
            }
            return;
        }
        if (game.turn_player_id === me.id) {
            banner.textContent = '🎣 Your turn! Pick a card, then pick a player to ask.';
            banner.classList.add('mine');
        } else {
            var p = players.find(function (pl) { return pl.id === game.turn_player_id; });
            banner.textContent = (p ? p.name : 'Someone') + "'s turn…";
        }
    }

    function updateLayoutVars() {
        var header = document.querySelector('.game-header');
        var hand = qs('hand-area');
        var root = document.documentElement;
        if (header) {
            root.style.setProperty('--header-h', header.offsetHeight + 'px');
        }
        if (hand && hand.offsetHeight > 0) {
            root.style.setProperty('--hand-h', hand.offsetHeight + 'px');
        }
    }

    function tickTurnTimer() {
        var el = qs('turn-timer');
        if (!state.turnDeadlineTs) {
            el.style.display = 'none';
            return;
        }
        var estServerNow = Math.floor(Date.now() / 1000) + state.clockSkewSec;
        var remaining = Math.max(0, state.turnDeadlineTs - estServerNow);
        el.style.display = 'flex';
        el.textContent = remaining;
        el.classList.toggle('urgent', remaining <= 10);
    }

    function selectFish(fish) {
        state.selectedFish = state.selectedFish === fish ? null : fish;
        state.selectedTarget = null;
        qs('ask-confirm').style.display = 'none';
        poll();
    }

    function selectTarget(player) {
        if (!state.selectedFish) return;
        state.selectedTarget = player;
        var ft = state.fishTypes[state.selectedFish];
        qs('ask-confirm-text').innerHTML = 'Ask <strong>' + escapeHtml(player.name) + '</strong> for ' +
            (ft ? ft.emoji + ' ' + ft.name : state.selectedFish) + '?';
        qs('ask-confirm').style.display = 'flex';
    }

    function clearAskSelection() {
        state.selectedFish = null;
        state.selectedTarget = null;
        qs('ask-confirm').style.display = 'none';
    }

    function submitAsk() {
        if (!state.selectedFish || !state.selectedTarget) return;
        var fish = state.selectedFish, target = state.selectedTarget;
        clearAskSelection();
        post('ask_card.php', {
            room_code: state.room,
            token: state.session.token,
            target_id: target.id,
            fish: fish
        }).then(poll).catch(function (err) { alert(err.message); });
    }

    // ---------- Scoreboard ----------

    function renderScoreboard(players) {
        var ranked = players.filter(function (p) { return !p.is_spectator; })
            .slice()
            .sort(function (a, b) { return (b.books || []).length - (a.books || []).length; });
        var list = qs('scoreboard-list');
        list.innerHTML = ranked.map(function (p, idx) {
            return '<li><span class="scoreboard-rank">#' + (idx + 1) + '</span>' +
                '<span class="scoreboard-name">' + escapeHtml(p.name) + (p.is_host ? ' 👑' : '') + '</span>' +
                '<span class="scoreboard-count">' + (p.books || []).length + ' 📚</span></li>';
        }).join('');
    }

    // ---------- Tiebreak ----------

    function renderTiebreak(game, players, me) {
        var tiedPlayers = game.tiebreak_player_ids.map(function (id) {
            return players.find(function (pl) { return pl.id === id; });
        });
        var names = tiedPlayers.map(function (p) { return p ? p.name : '?'; });
        var setsEach = tiedPlayers[0] && tiedPlayers[0].books ? tiedPlayers[0].books.length : 0;
        qs('tiebreak-desc').innerHTML = 'Tied at <strong>' + setsEach +
            '</strong> sets each: <strong>' + names.map(escapeHtml).join(', ') + '</strong>. ' +
            'Guess a fish, draw from the reset pond — match it and you win instantly!';

        var currentId = game.tiebreak_player_ids[game.tiebreak_turn_index];
        var currentPlayer = players.find(function (p) { return p.id === currentId; });
        var isMine = currentId === me.id;

        qs('tiebreak-turn-msg').textContent = isMine
            ? 'Your guess! Pick a fish:'
            : ((currentPlayer ? currentPlayer.name : '?') + ' is guessing…');

        var grid = qs('tiebreak-fish-grid');
        grid.innerHTML = '';
        Object.keys(state.fishTypes).forEach(function (key) {
            var ft = state.fishTypes[key];
            var btn = document.createElement('button');
            btn.disabled = !isMine;
            btn.innerHTML = '<span class="fg-emoji">' + ft.emoji + '</span>' + ft.name;
            btn.addEventListener('click', function () {
                grid.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
                post('tiebreak_guess.php', { room_code: state.room, token: state.session.token, guess: key })
                    .then(poll)
                    .catch(function (err) { alert(err.message); poll(); });
            });
            grid.appendChild(btn);
        });
    }

    // ---------- Winner ----------

    function renderWinner(game, players, me) {
        var winner = players.find(function (p) { return p.id === game.winner_player_id; });
        qs('winner-title').textContent = game.winner_player_id === me.id ? '🏆 You win!' : '🏆 We have a winner!';
        qs('winner-name').textContent = winner ? winner.name : '?';
        var booksWrap = qs('winner-books');
        booksWrap.innerHTML = '';
        players.filter(function (p) { return !p.is_spectator; })
            .slice()
            .sort(function (a, b) { return (b.books || []).length - (a.books || []).length; })
            .forEach(function (p) {
                var row = document.createElement('div');
                row.style.cssText = 'display:flex;flex-direction:column;align-items:center;gap:4px;margin:0 6px;';
                var chips = (p.books || []).map(function (fish) {
                    var ft = state.fishTypes[fish];
                    return '<div class="book-chip">' + (ft ? ft.emoji : '🐟') + '</div>';
                }).join('');
                row.innerHTML = '<div style="font-size:12px;font-weight:700;">' + escapeHtml(p.name) + ' (' + (p.books || []).length + ')</div>' +
                    '<div class="seat-books">' + chips + '</div>';
                booksWrap.appendChild(row);
            });

        var playAgainBtn = qs('play-again-btn');
        var waitMsg = qs('play-again-wait');
        if (me.is_host) {
            playAgainBtn.style.display = 'inline-block';
            waitMsg.style.display = 'none';
        } else {
            playAgainBtn.style.display = 'none';
            waitMsg.style.display = 'block';
        }
    }

    // ---------- Events / toasts / fishing animation ----------

    function handleEvent(game, players) {
        if (game.event_seq === state.lastSeenEventSeq) return;
        var firstLoad = state.lastSeenEventSeq === -1;
        state.lastSeenEventSeq = game.event_seq;
        if (firstLoad) return;

        var ev = game.last_event || {};
        var nameOf = function (id) {
            var p = players.find(function (pl) { return pl.id === id; });
            return p ? p.name : 'Someone';
        };
        var ft = ev.fish ? state.fishTypes[ev.fish] : null;
        var msg = null;

        switch (ev.type) {
            case 'player_joined':
                msg = ev.name + (ev.spectator ? ' joined to spectate 👀' : ' joined the pond 🎣');
                break;
            case 'player_left':
                msg = ev.name + ' left the pond.' + (ev.new_host ? ' ' + ev.new_host + ' is now the host.' : '');
                break;
            case 'player_kicked':
                msg = (ev.by || 'The host') + ' kicked ' + ev.name + '.' + (ev.new_host ? ' ' + ev.new_host + ' is now the host.' : '');
                break;
            case 'new_round':
                msg = (ev.by || 'The host') + ' started a new round!';
                break;
            case 'turn_timeout':
                msg = nameOf(ev.player_id) + ' ran out of time — turn skipped.';
                break;
            case 'game_started':
                msg = 'The game has begun! Cards are dealt.';
                break;
            case 'go_fish_prompt':
                msg = nameOf(ev.asker_id) + ' asks ' + nameOf(ev.target_id) + ' for ' + (ft ? ft.emoji + ' ' + ft.name : '') + '…';
                break;
            case 'go_fish_result':
                animateFishing();
                playSplash();
                var prefix = ev.timed_out ? (nameOf(ev.target_id) + " didn't respond in time, so " + nameOf(ev.asker_id) + ' went fishing') : (nameOf(ev.asker_id) + ' went fishing');
                if (ev.matched) {
                    msg = prefix.replace('went fishing', 'fished up a ' + (ft ? ft.emoji + ' ' + ft.name : '') + '!') + ' Next player\'s turn.';
                } else if (ev.pond_empty) {
                    msg = prefix + ' but the pond is empty!';
                } else {
                    msg = prefix + '… no match. Next player\'s turn.';
                }
                if (ev.sets_formed && ev.sets_formed.length) {
                    msg += ' Completed a set!';
                    setTimeout(function () { playSetCompleteSound(); showSetPopup(nameOf(ev.asker_id), ev.sets_formed); }, 200);
                }
                break;
            case 'caught':
                playCatchSound();
                msg = nameOf(ev.target_id) + ' handed over ' + ev.count + '× ' + (ft ? ft.emoji + ' ' + ft.name : '') + ' to ' + nameOf(ev.asker_id) + '!';
                if (ev.sets_formed && ev.sets_formed.length) {
                    msg += ' 📚 Set completed!';
                    setTimeout(function () { playSetCompleteSound(); showSetPopup(nameOf(ev.asker_id), ev.sets_formed); }, 200);
                }
                break;
            case 'set_completed':
                msg = nameOf(ev.player_id) + ' drew a fresh hand and completed a set!';
                playSetCompleteSound();
                showSetPopup(nameOf(ev.player_id), ev.sets);
                break;
            case 'tiebreak_start':
                msg = "It's a tie! Time for a luck round 🍀";
                break;
            case 'tiebreak_guess':
                playSplash();
                var gft = state.fishTypes[ev.guess];
                var who = nameOf(ev.player_id) + (ev.timed_out ? ' (out of time, random guess)' : '');
                msg = who + ' guessed ' + (gft ? gft.name : ev.guess) + (ev.matched ? ' — MATCH! 🎉' : ' — no match.');
                break;
            case 'game_finished':
                msg = ev.winner_id ? (nameOf(ev.winner_id) + ' wins the game! 🏆') : 'The game has ended.';
                break;
        }

        if (msg) showToast(msg);
    }

    var toastTimer = null;
    function showToast(msg) {
        var t = qs('toast');
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.classList.remove('show'); }, 3200);
    }

    var setPopupTimer = null;
    function showSetPopup(name, fishKeys) {
        var el = qs('set-popup');
        if (!el || !fishKeys || !fishKeys.length) return;
        var names = fishKeys.map(function (k) {
            var ft = state.fishTypes[k];
            return ft ? ft.name : k;
        });
        var mainEmoji = (state.fishTypes[fishKeys[0]] && state.fishTypes[fishKeys[0]].emoji) || '🎉';
        el.innerHTML = '<span class="sp-emoji">' + mainEmoji + '</span>' +
            escapeHtml(name) + ' completed a set of ' + escapeHtml(names.join(', ')) + '!';
        el.hidden = false;
        el.classList.remove('show');
        void el.offsetWidth; // restart animation
        el.classList.add('show');
        clearTimeout(setPopupTimer);
        setPopupTimer = setTimeout(function () { el.hidden = true; el.classList.remove('show'); }, 2300);
    }

    function animateFishing() {
        var line = qs('fishing-line');
        if (!line) return;
        line.hidden = false;
        line.textContent = '🎣';
        line.style.left = '50%';
        line.style.top = '30%';
        // restart animation
        line.style.animation = 'none';
        void line.offsetWidth;
        line.style.animation = '';
        setTimeout(function () { line.hidden = true; }, 1100);
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    window.GoFish = {
        initLanding: initLanding,
        initGame: initGame
    };
})();
