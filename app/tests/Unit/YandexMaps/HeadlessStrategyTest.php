<?php

use App\Services\YandexMaps\Exceptions\ParsingException;
use App\Services\YandexMaps\ParseContext;
use App\Services\YandexMaps\Strategies\HeadlessStrategy;

it('raises a parsing exception when the headless script cannot run', function (): void {
    config(['yandex.headless_script' => 'resources/headless/does-not-exist.mjs']);

    expect(fn () => app(HeadlessStrategy::class)->parse(mapsOrganization(), new ParseContext))
        ->toThrow(ParsingException::class);
});
