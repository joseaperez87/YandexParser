<?php

return [
    'strategy' => env('YANDEX_MAPS_STRATEGY', 'json'),
    'region' => env('YANDEX_MAPS_REGION', 'ru'),
    'timeout' => (int) env('YANDEX_MAPS_TIMEOUT', 15),
    'verify' => env('YANDEX_MAPS_VERIFY_SSL', true),
    'max_reviews' => (int) env('YANDEX_MAPS_MAX_REVIEWS', 600),
    'throttle_ms' => (int) env('YANDEX_MAPS_THROTTLE_MS', 800),
    'throttle_jitter_ms' => (int) env('YANDEX_MAPS_THROTTLE_JITTER_MS', 400),
    'headless_enabled' => filter_var(env('YANDEX_MAPS_HEADLESS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'headless_script' => env('YANDEX_MAPS_HEADLESS_SCRIPT'),
    'retries' => (int) env('YANDEX_MAPS_RETRIES', 3),
    'backoff_base_ms' => (int) env('YANDEX_MAPS_BACKOFF_BASE_MS', 1000),
    'reviews_endpoint' => env('YANDEX_MAPS_REVIEWS_ENDPOINT', '/maps/api/business/fetchReviews'),
    'locale' => env('YANDEX_MAPS_LOCALE', 'ru_RU'),
    'ranking' => env('YANDEX_MAPS_RANKING', 'by_relevance_org'),
    'max_pages' => (int) env('YANDEX_MAPS_MAX_PAGES', 12),
    'page_size' => 50,
    'user_agents' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/18.1 Safari/605.1.15',
    ],
];
