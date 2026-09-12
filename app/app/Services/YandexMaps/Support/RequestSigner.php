<?php

declare(strict_types=1);

namespace App\Services\YandexMaps\Support;

final class RequestSigner
{
    /**
     * Подпись query для внутреннего API Яндекс.Карт:
     * сортировка ключей (без учёта регистра) + хеш djb2 (seed 5381).
     */
    public static function sign(array $params): string
    {
        $params = array_filter(
            $params,
            static fn (mixed $value): bool => $value !== null,
        );

        uksort(
            $params,
            static fn (string $a, string $b): int => strcasecmp($a, $b),
        );

        $parts = [];

        foreach ($params as $key => $value) {
            $parts[] = rawurlencode((string) $key).'='.rawurlencode(self::stringify($value));
        }

        $query = implode('&', $parts);
        $hash = 5381;

        for ($i = 0, $length = strlen($query); $i < $length; $i++) {
            $hash = (((33 * $hash) & 0xFFFFFFFF) ^ ord($query[$i])) & 0xFFFFFFFF;
        }

        return (string) $hash;
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
