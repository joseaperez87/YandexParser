<?php

declare(strict_types=1);

namespace App\Services\YandexMaps;

use App\Models\Organization;
use App\Services\YandexMaps\Exceptions\BlockedException;
use App\Services\YandexMaps\Exceptions\ParsingException;
use App\Services\YandexMaps\Exceptions\SourceChangedException;
use App\Services\YandexMaps\Strategies\HeadlessStrategy;
use App\Services\YandexMaps\Strategies\JsonApiStrategy;
use Illuminate\Support\Facades\Log;

class YandexMapsParser
{
    public function __construct(
        private readonly JsonApiStrategy $jsonStrategy,
        private readonly HeadlessStrategy $headlessStrategy,
    ) {}

    public function parse(Organization $organization, ParseContext $context): ParserResult
    {
        if (config('yandex.strategy') === 'headless') {
            return $this->headlessStrategy->parse($organization, $context);
        }

        try {
            return $this->withRetries(fn (): ParserResult => $this->jsonStrategy->parse($organization, $context));
        } catch (SourceChangedException|BlockedException $exception) {
            if (! config('yandex.headless_enabled')) {
                throw $exception;
            }

            Log::warning('Yandex Maps JSON strategy failed, falling back to headless.', [
                'organization_id' => $organization->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->headlessStrategy->parse($organization, $context);
        }
    }

    private function withRetries(callable $callback): ParserResult
    {
        $attempts = max(1, (int) config('yandex.retries', 3));
        $backoff = max(0, (int) config('yandex.backoff_base_ms', 1000));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $callback();
            } catch (BlockedException $exception) {
                if ($attempt === $attempts) {
                    Log::error('Yandex Maps blocked the request on every retry attempt.', [
                        'attempts' => $attempts,
                        'error' => $exception->getMessage(),
                    ]);

                    throw $exception;
                }

                usleep($backoff * (2 ** ($attempt - 1)) * 1000);
            }
        }

        throw new ParsingException('Yandex Maps parsing failed after retries.');
    }
}
