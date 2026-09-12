<?php

use App\Services\YandexMaps\Exceptions\BlockedException;
use App\Services\YandexMaps\Exceptions\SourceChangedException;
use App\Services\YandexMaps\ParseContext;
use App\Services\YandexMaps\ParserResult;
use App\Services\YandexMaps\Strategies\HeadlessStrategy;
use App\Services\YandexMaps\Strategies\JsonApiStrategy;
use App\Services\YandexMaps\YandexMapsParser;
use Mockery\MockInterface;

it('falls back to the headless strategy when the JSON schema changes', function (): void {
    config(['yandex.headless_enabled' => true]);

    $json = $this->mock(JsonApiStrategy::class, function (MockInterface $mock): void {
        $mock->shouldReceive('parse')->once()->andThrow(new SourceChangedException('schema changed'));
    });

    $headless = $this->mock(HeadlessStrategy::class, function (MockInterface $mock): void {
        $mock->shouldReceive('parse')->once()->andReturn(new ParserResult([], 4.5, 10, 2));
    });

    $result = app(YandexMapsParser::class)->parse(mapsOrganization(), new ParseContext);

    expect($result->rating)->toBe(4.5)
        ->and($result->ratingsCount)->toBe(10);
});

it('rethrows a schema change when the headless fallback is disabled', function (): void {
    config(['yandex.headless_enabled' => false]);

    $this->mock(JsonApiStrategy::class, function (MockInterface $mock): void {
        $mock->shouldReceive('parse')->once()->andThrow(new SourceChangedException('schema changed'));
    });

    expect(fn () => app(YandexMapsParser::class)->parse(mapsOrganization(), new ParseContext))
        ->toThrow(SourceChangedException::class);
});

it('retries a blocked request and succeeds on a later attempt', function (): void {
    config(['yandex.headless_enabled' => false, 'yandex.retries' => 3, 'yandex.backoff_base_ms' => 0]);

    $calls = 0;
    $this->mock(JsonApiStrategy::class, function (MockInterface $mock) use (&$calls): void {
        $mock->shouldReceive('parse')->twice()->andReturnUsing(function () use (&$calls): ParserResult {
            $calls++;

            if ($calls === 1) {
                throw new BlockedException('blocked');
            }

            return new ParserResult([], 4.5, 10, 2);
        });
    });

    $result = app(YandexMapsParser::class)->parse(mapsOrganization(), new ParseContext);

    expect($result->rating)->toBe(4.5)
        ->and($result->ratingsCount)->toBe(10);
});

it('gives up after the configured number of blocked attempts', function (): void {
    config(['yandex.headless_enabled' => false, 'yandex.retries' => 3, 'yandex.backoff_base_ms' => 0]);

    $this->mock(JsonApiStrategy::class, function (MockInterface $mock): void {
        $mock->shouldReceive('parse')->times(3)->andThrow(new BlockedException('blocked'));
    });

    expect(fn () => app(YandexMapsParser::class)->parse(mapsOrganization(), new ParseContext))
        ->toThrow(BlockedException::class);
});
