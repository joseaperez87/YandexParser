<?php

declare(strict_types=1);

namespace App\Services\YandexMaps\Support;

use Symfony\Component\DomCrawler\Crawler;

final class CardMetadata
{
    /**
     * @return array{
     *     title: ?string,
     *     address: ?string,
     *     rating: ?float,
     *     ratings_count: ?int,
     *     reviews_count: ?int,
     *     csrf_token: ?string,
     *     session_id: ?string,
     *     request_id: ?string
     * }
     */
    public static function fromHtml(string $html): array
    {
        $crawler = new Crawler($html);

        return [
            'title' => self::title($crawler, $html),
            'address' => self::address($crawler, $html),
            'rating' => self::floatOrNull(self::microdata($crawler, 'ratingValue')),
            'ratings_count' => self::intOrNull(self::microdata($crawler, 'ratingCount')),
            'reviews_count' => self::intOrNull(self::microdata($crawler, 'reviewCount')),
            'csrf_token' => self::match($html, '/"csrfToken":"([^"]+)"/'),
            'session_id' => self::match($html, '/"sessionId":"([^"]+)"/'),
            'request_id' => self::match($html, '/"requestId":"([^"]+)"/'),
        ];
    }

    private static function title(Crawler $crawler, string $html): ?string
    {
        $name = $crawler->filter('[itemprop="name"]');

        if ($name->count() > 0) {
            $value = self::clean($name->first()->text());

            if ($value !== null) {
                return $value;
            }
        }

        $ogTitle = self::metaContent($crawler, 'meta[property="og:title"]');

        if ($ogTitle !== null) {
            return $ogTitle;
        }

        return self::fromJsonLd($html, ['name']) ?? self::tagTitle($crawler);
    }

    private static function address(Crawler $crawler, string $html): ?string
    {
        $jsonLd = self::fromJsonLd($html, ['streetAddress', 'address']);

        if ($jsonLd !== null) {
            return $jsonLd;
        }

        return self::match($html, '/"address":"([^"]+)"/');
    }

    private static function microdata(Crawler $crawler, string $field): ?string
    {
        $node = $crawler->filter('[itemprop="'.$field.'"]');

        if ($node->count() === 0) {
            return null;
        }

        return self::clean($node->first()->attr('content') ?? $node->first()->text());
    }

    private static function metaContent(Crawler $crawler, string $selector): ?string
    {
        $node = $crawler->filter($selector);

        return $node->count() > 0 ? self::clean($node->first()->attr('content')) : null;
    }

    private static function tagTitle(Crawler $crawler): ?string
    {
        $title = $crawler->filter('title');

        return $title->count() > 0 ? self::clean($title->text()) : null;
    }

    /**
     * @param  list<string>  $keys
     */
    private static function fromJsonLd(string $html, array $keys): ?string
    {
        if (! preg_match_all('~<script[^>]+application/ld\+json[^>]*>(.*?)</script>~is', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $json) {
            $decoded = json_decode(trim($json), true);

            if (is_array($decoded)) {
                $value = self::searchJsonLd($decoded, $keys);

                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $keys
     */
    private static function searchJsonLd(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $candidate = self::clean(is_scalar($data[$key]) ? (string) $data[$key] : null);

                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = self::searchJsonLd($value, $keys);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private static function match(string $html, string $pattern): ?string
    {
        return preg_match($pattern, $html, $matches) ? $matches[1] : null;
    }

    private static function floatOrNull(?string $value): ?float
    {
        return $value !== null && is_numeric($value) ? (float) $value : null;
    }

    private static function intOrNull(?string $value): ?int
    {
        return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value === '' ? null : $value;
    }
}
