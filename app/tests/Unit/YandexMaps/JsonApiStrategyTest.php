<?php

use App\Services\YandexMaps\Exceptions\BlockedException;
use App\Services\YandexMaps\Exceptions\EmptyResponseException;
use App\Services\YandexMaps\Exceptions\SourceChangedException;
use App\Services\YandexMaps\ParseContext;
use App\Services\YandexMaps\Strategies\JsonApiStrategy;
use Illuminate\Support\Facades\Http;

function mapsCard(): string
{
    return file_get_contents(__DIR__.'/../../Fixtures/yandex/card.html');
}

it('collects reviews, aggregates and card metadata', function (): void {
    Http::fake([
        'https://yandex.ru/maps/org/cafe/1234567890*' => Http::response(mapsCard()),
        'https://yandex.ru/maps/api/business/fetchReviews*' => Http::response(mapsPage([
            mapsReview('r-1', 'Иван', 5, 'Отлично', '2026-08-01T12:30:00Z'),
            mapsReview('r-2', 'Ольга', 4, 'Хорошо', '2026-08-02T12:30:00Z'),
            mapsReview('r-3', 'Павел', 5, 'Супер', '2026-08-03T12:30:00Z'),
        ], ['count' => 3])),
    ]);

    $result = app(JsonApiStrategy::class)->parse(mapsOrganization(), new ParseContext);

    expect($result->reviews)->toHaveCount(3)
        ->and($result->reviews[0]['external_id'])->toBe('r-1')
        ->and($result->reviews[0]['author'])->toBe('Иван')
        ->and($result->reviews[0]['rating'])->toBe(5)
        ->and($result->rating)->toBe(4.6)
        ->and($result->ratingsCount)->toBe(812)
        ->and($result->reviewsCount)->toBe(3)
        ->and($result->title)->toBe('Кафе Пушкин')
        ->and($result->address)->toBe('г. Москва, ул. Тверская, 1')
        ->and($result->status)->toBe('ready');

    Http::assertSentCount(2);
});

it('paginates until the last page', function (): void {
    Http::fake([
        'https://yandex.ru/maps/org/cafe/1234567890*' => Http::response(mapsCard()),
        'https://yandex.ru/maps/api/business/fetchReviews*' => Http::sequence()
            ->push(mapsPage([
                mapsReview('r-1', 'Иван', 5, 'Отлично'),
                mapsReview('r-2', 'Ольга', 4, 'Хорошо'),
            ], ['count' => 3, 'page' => 1, 'totalPages' => 2, 'reviewsRemained' => 1]))
            ->push(mapsPage([
                mapsReview('r-3', 'Павел', 5, 'Супер'),
            ], ['count' => 3, 'page' => 2, 'totalPages' => 2, 'reviewsRemained' => 0])),
    ]);

    $result = app(JsonApiStrategy::class)->parse(mapsOrganization(), new ParseContext);

    expect($result->reviews)->toHaveCount(3)
        ->and($result->reviews[2]['external_id'])->toBe('r-3')
        ->and($result->status)->toBe('ready');

    Http::assertSentCount(3);
});

it('stops at the configured maximum number of reviews', function (): void {
    config(['yandex.max_reviews' => 2]);

    Http::fake([
        'https://yandex.ru/maps/org/cafe/1234567890*' => Http::response(mapsCard()),
        'https://yandex.ru/maps/api/business/fetchReviews*' => Http::response(mapsPage([
            mapsReview('r-1', 'Иван', 5, 'Один'),
            mapsReview('r-2', 'Ольга', 4, 'Два'),
            mapsReview('r-3', 'Павел', 3, 'Три'),
        ], ['count' => 3, 'totalPages' => 3])),
    ]);

    $result = app(JsonApiStrategy::class)->parse(mapsOrganization(), new ParseContext);

    expect($result->reviews)->toHaveCount(2);
});

it('retries once with a fresh csrf token', function (): void {
    Http::fake([
        'https://yandex.ru/maps/org/cafe/1234567890*' => Http::response(mapsCard()),
        'https://yandex.ru/maps/api/business/fetchReviews*' => Http::sequence()
            ->push(['csrfToken' => 'fresh-token'])
            ->push(mapsPage([mapsReview('r-1', 'Иван', 5, 'Отлично')], ['count' => 1])),
    ]);

    $result = app(JsonApiStrategy::class)->parse(mapsOrganization(), new ParseContext);

    expect($result->reviews)->toHaveCount(1);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'fetchReviews') && str_contains($request->url(), 'csrfToken=fresh-token');
    });
});

it('rejects a changed response schema', function (): void {
    Http::fake([
        'https://yandex.ru/maps/org/cafe/1234567890*' => Http::response(mapsCard()),
        'https://yandex.ru/maps/api/business/fetchReviews*' => Http::response(['data' => ['foo' => 'bar']]),
    ]);

    expect(fn () => app(JsonApiStrategy::class)->parse(mapsOrganization(), new ParseContext))
        ->toThrow(SourceChangedException::class);
});

it('rejects an empty response when the source reports reviews', function (): void {
    Http::fake([
        'https://yandex.ru/maps/org/cafe/1234567890*' => Http::response(mapsCard()),
        'https://yandex.ru/maps/api/business/fetchReviews*' => Http::response(
            mapsPage([], ['count' => 5]),
        ),
    ]);

    expect(fn () => app(JsonApiStrategy::class)->parse(mapsOrganization(), new ParseContext))
        ->toThrow(EmptyResponseException::class);
});

it('raises a blocked exception for a captcha response', function (): void {
    Http::fake([
        'https://yandex.ru/maps/org/cafe/1234567890*' => Http::response(mapsCard()),
        'https://yandex.ru/maps/api/business/fetchReviews*' => Http::response([
            'type' => 'captcha',
            'captcha' => ['captcha-page' => 'https://yandex.ru/showcaptcha'],
        ]),
    ]);

    expect(fn () => app(JsonApiStrategy::class)->parse(mapsOrganization(), new ParseContext))
        ->toThrow(BlockedException::class);
});

it('does not treat an incidental captcha substring as a block', function (): void {
    $card = str_replace('</head>', '<script>{"liburl":"https://yandex.ru/captchapgrd"}</script></head>', mapsCard());

    Http::fake([
        'https://yandex.ru/maps/org/cafe/1234567890*' => Http::response($card),
        'https://yandex.ru/maps/api/business/fetchReviews*' => Http::response(
            mapsPage([mapsReview('r-1', 'Иван', 5, 'Отлично')], ['count' => 1]),
        ),
    ]);

    $result = app(JsonApiStrategy::class)->parse(mapsOrganization(), new ParseContext);

    expect($result->reviews)->toHaveCount(1);
});
