<?php

declare(strict_types=1);

namespace App\Services\YandexMaps;

use Closure;

final readonly class ParseContext
{
    public function __construct(
        public ?string $businessId = null,
        public ?string $csrfToken = null,
        public array $cookies = [],
        public array $headers = [],
        public int $page = 1,
        public int $pageSize = 50,
        public ?Closure $onProgress = null,
    ) {}
}
