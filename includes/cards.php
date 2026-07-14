<?php
declare(strict_types=1);

/**
 * The full master list of fish types (up to 21). Each type has 4 copies of
 * itself in the deck. Games with 2-6 players use just the first 13 (52-card
 * standard deck); games with 7-10 players use more of the list so there are
 * enough cards to go around (see active_set_count()).
 * "emoji" is the placeholder art shown on the card face until real PNGs are
 * dropped into assets/img/cards/{key}.png (see assets/js/app.js CARD_IMAGE_OVERRIDE).
 */
function fish_types(): array {
    return [
        'shrimp'     => ['name' => 'Shrimp',     'number' => 1,  'emoji' => '🦐'],
        'whale'      => ['name' => 'Whale',      'number' => 2,  'emoji' => '🐋'],
        'crab'       => ['name' => 'Crab',       'number' => 3,  'emoji' => '🦀'],
        'octopus'    => ['name' => 'Octopus',    'number' => 4,  'emoji' => '🐙'],
        'squid'      => ['name' => 'Squid',      'number' => 5,  'emoji' => '🦑'],
        'jellyfish'  => ['name' => 'Jellyfish',  'number' => 6,  'emoji' => '🎐'],
        'pufferfish' => ['name' => 'Pufferfish', 'number' => 7,  'emoji' => '🐡'],
        'clownfish'  => ['name' => 'Clownfish',  'number' => 8,  'emoji' => '🐠'],
        'dolphin'    => ['name' => 'Dolphin',    'number' => 9,  'emoji' => '🐬'],
        'shark'      => ['name' => 'Shark',      'number' => 10, 'emoji' => '🦈'],
        'turtle'     => ['name' => 'Turtle',     'number' => 11, 'emoji' => '🐢'],
        'seal'       => ['name' => 'Seal',       'number' => 12, 'emoji' => '🦭'],
        'lobster'    => ['name' => 'Lobster',    'number' => 13, 'emoji' => '🦞'],
        'starfish'   => ['name' => 'Starfish',   'number' => 14, 'emoji' => '⭐'],
        'otter'      => ['name' => 'Otter',      'number' => 15, 'emoji' => '🦦'],
        'frog'       => ['name' => 'Frog',       'number' => 16, 'emoji' => '🐸'],
        'duck'       => ['name' => 'Duck',       'number' => 17, 'emoji' => '🦆'],
        'swan'       => ['name' => 'Swan',       'number' => 18, 'emoji' => '🦢'],
        'penguin'    => ['name' => 'Penguin',    'number' => 19, 'emoji' => '🐧'],
        'snail'      => ['name' => 'Snail',      'number' => 20, 'emoji' => '🐌'],
        'crocodile'  => ['name' => 'Crocodile',  'number' => 21, 'emoji' => '🐊'],
    ];
}

const BASE_FISH_SET_COUNT = 13;
const MAX_FISH_SET_COUNT = 21;

/** How many fish types should be in play for a game with this many players. */
function fish_set_count_for(int $playerCount): int {
    $extraPlayers = max(0, $playerCount - 6);
    return min(MAX_FISH_SET_COUNT, BASE_FISH_SET_COUNT + $extraPlayers * 2);
}

/** The subset of fish_types() actually in play for a game (preserves key order/numbering). */
function active_fish_types(int $setCount): array {
    $setCount = max(BASE_FISH_SET_COUNT, min(MAX_FISH_SET_COUNT, $setCount));
    return array_slice(fish_types(), 0, $setCount, true);
}

function fish_keys(): array {
    return array_keys(fish_types());
}

function active_fish_keys(int $setCount): array {
    return array_keys(active_fish_types($setCount));
}

function is_valid_fish(string $key, int $setCount = BASE_FISH_SET_COUNT): bool {
    return array_key_exists($key, active_fish_types($setCount));
}

/** Fresh shuffled deck (4 copies of each active fish type) as an array of fish-type strings. */
function build_deck(int $setCount = BASE_FISH_SET_COUNT): array {
    $deck = [];
    foreach (active_fish_keys($setCount) as $key) {
        for ($i = 0; $i < 4; $i++) {
            $deck[] = $key;
        }
    }
    shuffle($deck);
    return $deck;
}
