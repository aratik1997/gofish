<?php
declare(strict_types=1);

/**
 * The 13 fish types. Each type has 4 copies in the deck (52 cards total).
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
    ];
}

function fish_keys(): array {
    return array_keys(fish_types());
}

function is_valid_fish(string $key): bool {
    return array_key_exists($key, fish_types());
}

/** Fresh shuffled 52-card deck as an array of fish-type keys. */
function build_deck(): array {
    $deck = [];
    foreach (fish_keys() as $key) {
        for ($i = 0; $i < 4; $i++) {
            $deck[] = $key;
        }
    }
    shuffle($deck);
    return $deck;
}
