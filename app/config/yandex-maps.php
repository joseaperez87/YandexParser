<?php

return [
    'strategy' => env('YANDEX_MAPS_STRATEGY', 'json'),

    'region' => env('YANDEX_MAPS_REGION', 'ru'),

    'timeout' => (int) env('YANDEX_MAPS_TIMEOUT', 15),

    'max_reviews' => (int) env('YANDEX_MAPS_MAX_REVIEWS', 600),

    'throttle_ms' => (int) env('YANDEX_MAPS_THROTTLE_MS', 800),

    'headless' => [
        'enabled' => (bool) env('YANDEX_MAPS_HEADLESS_ENABLED', false),
        'node_binary' => env('YANDEX_MAPS_NODE_BINARY', 'node'),
        'script' => base_path('resources/headless/fetch.mjs'),
    ],

    'user_agents' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ],
];
