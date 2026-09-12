<?php

declare(strict_types=1);

namespace App\Services\YandexMaps\Strategies;

use App\Models\Organization;
use App\Services\YandexMaps\Contracts\ParserStrategy;
use App\Services\YandexMaps\Exceptions\EmptyResponseException;
use App\Services\YandexMaps\Exceptions\ParsingException;
use App\Services\YandexMaps\ParseContext;
use App\Services\YandexMaps\ParserResult;
use App\Services\YandexMaps\Support\ReviewNormalizer;
use Symfony\Component\Process\Process;

class HeadlessStrategy implements ParserStrategy
{
    public function parse(Organization $organization, ParseContext $context): ParserResult
    {
        $script = (string) config('yandex.headless_script', base_path('resources/headless/fetch.mjs'));
        $url = (string) $organization->url;
        $businessId = $context->businessId ?? $organization->business_id;

        $process = new Process(['node', $script, $url, (string) $businessId]);
        $process->setTimeout((int) config('yandex.timeout', 15) + 120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ParsingException('Headless parsing failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        $payload = json_decode($process->getOutput(), true);

        ReviewNormalizer::assertSchema($payload);

        $reviews = [];
        $seen = [];
        $pageReviews = is_array($payload['reviews']) ? $payload['reviews'] : [];

        foreach ($pageReviews as $review) {
            $normalized = ReviewNormalizer::review($review);

            if ($normalized['external_id'] === null || isset($seen[$normalized['external_id']])) {
                continue;
            }

            $seen[$normalized['external_id']] = true;
            $reviews[] = $normalized;
        }

        $reviewsCount = (int) $payload['reviewsCount'];

        if ($reviewsCount > 0 && $reviews === []) {
            throw new EmptyResponseException('Yandex Maps returned no reviews for a non-empty organization.');
        }

        $status = $reviewsCount > count($reviews) ? 'partial' : 'ready';

        if ($context->onProgress !== null) {
            ($context->onProgress)(count($reviews), $reviewsCount);
        }

        return new ParserResult(
            reviews: $reviews,
            rating: $payload['rating'] === null ? null : (float) $payload['rating'],
            ratingsCount: (int) $payload['ratingsCount'],
            reviewsCount: $reviewsCount,
            status: $status,
            warnings: $status === 'partial' ? ['The collected review count differs from the source aggregate.'] : [],
            businessId: (string) $businessId,
            title: $this->stringOrNull($payload['title'] ?? $payload['name'] ?? $payload['business']['name'] ?? null),
            address: $this->addressOrNull($payload['address'] ?? $payload['business']['address'] ?? null),
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function addressOrNull(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['streetAddress'] ?? $value['value'] ?? null;
        }

        return $this->stringOrNull($value);
    }
}
