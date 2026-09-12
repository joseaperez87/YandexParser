<?php

declare(strict_types=1);

namespace App\Services\YandexMaps\Support;

use App\Services\YandexMaps\Exceptions\SourceChangedException;

final class ReviewNormalizer
{
    /**
     * Схема ответа внутреннего JSON API (`business/fetchReviews`).
     */
    public static function assertData(mixed $json): void
    {
        if (! is_array($json) || ! isset($json['data']) || ! is_array($json['data'])) {
            throw new SourceChangedException('Yandex Maps reviews response schema changed.');
        }

        if (! array_key_exists('reviews', $json['data']) || ! is_array($json['data']['reviews'])) {
            throw new SourceChangedException('Yandex Maps reviews list is missing.');
        }
    }

    /**
     * Схема ответа headless-скрипта (совместимый формат).
     */
    public static function assertSchema(mixed $payload): void
    {
        $required = ['reviews', 'rating', 'ratingsCount', 'reviewsCount', 'csrfToken'];

        if (! is_array($payload) || array_diff($required, array_keys($payload)) !== []) {
            throw new SourceChangedException('Yandex Maps reviews response schema changed.');
        }
    }

    /**
     * @return array{external_id: ?string, author: ?string, rating: ?int, text: ?string, published_at: mixed}
     */
    public static function review(mixed $review): array
    {
        if (! is_array($review)) {
            return [
                'external_id' => null,
                'author' => null,
                'rating' => null,
                'text' => null,
                'published_at' => null,
            ];
        }

        return [
            'external_id' => self::string($review['reviewId'] ?? $review['id'] ?? $review['externalId'] ?? null),
            'author' => self::author($review),
            'rating' => isset($review['rating']) ? (int) $review['rating'] : null,
            'text' => isset($review['text']) && $review['text'] !== '' ? (string) $review['text'] : null,
            'published_at' => $review['updatedTime'] ?? $review['createdTime'] ?? $review['publishedAt'] ?? $review['published_at'] ?? null,
        ];
    }

    private static function author(array $review): ?string
    {
        if (isset($review['author']) && is_array($review['author'])) {
            return self::string($review['author']['name'] ?? null);
        }

        if (isset($review['author']) && is_string($review['author'])) {
            return self::string($review['author']);
        }

        return self::string($review['name'] ?? null);
    }

    private static function string(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
