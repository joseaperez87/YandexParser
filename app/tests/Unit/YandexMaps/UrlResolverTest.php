<?php

use App\Services\YandexMaps\UrlResolver;
use Illuminate\Support\Facades\Http;

it('normalizes a canonical organization URL and extracts the business id', function (): void {
    $resolved = app(UrlResolver::class)->resolve(
        'https://yandex.ru/maps/org/cafe/1234567890/?ll=37.6,55.7#reviews',
    );

    expect($resolved)->toBe([
        'url' => 'https://yandex.ru/maps/org/cafe/1234567890/',
        'business_id' => '1234567890',
    ]);
});

it('follows a short URL redirect before extracting the business id', function (): void {
    Http::fake([
        'https://yandex.ru/maps/-/short*' => Http::response('', 302, [
            'Location' => 'https://yandex.com/maps/org/shop/987654321/?ll=1',
        ]),
    ]);

    $resolved = app(UrlResolver::class)->resolve('https://yandex.ru/maps/-/short');

    expect($resolved['url'])->toBe('https://yandex.com/maps/org/shop/987654321/');
    expect($resolved['business_id'])->toBe('987654321');
});

it('rejects non-Yandex map URLs', function (): void {
    expect(fn (): array => app(UrlResolver::class)->resolve('https://example.com/maps/org/shop/1/'))
        ->toThrow(InvalidArgumentException::class);
});
