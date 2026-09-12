<?php

declare(strict_types=1);

namespace App\Services\YandexMaps;

final readonly class ParserResult
{
    public function __construct(
        public array $reviews,
        public ?float $rating,
        public int $ratingsCount,
        public int $reviewsCount,
        public string $status = 'ready',
        public array $warnings = [],
        public ?string $businessId = null,
        public ?string $title = null,
        public ?string $address = null,
    ) {}
}
