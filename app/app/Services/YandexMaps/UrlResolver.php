<?php

declare(strict_types=1);

namespace App\Services\YandexMaps;

use App\Support\AntiBot\ProxyPool;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class UrlResolver
{
    public function __construct(
        private readonly ProxyPool $proxyPool,
    ) {}

    /**
     * @return array{url: string, business_id: string}
     */
    public function resolve(string $url): array
    {
        $parsed = $this->parseUrl($url);
        $normalized = $this->normalize($parsed);

        if (str_starts_with($parsed['path'], '/maps/-/')) {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'verify' => config('yandex.verify'),
                'proxy' => $this->proxyPool->next(),
            ])
                ->timeout((int) config('yandex.timeout', 15))
                ->get($normalized);

            if (! $response->redirect() || ! $response->header('Location')) {
                throw new InvalidArgumentException('The short Yandex Maps URL did not resolve.');
            }

            $redirect = $response->header('Location');
            $redirectUrl = str_starts_with($redirect, '/')
                ? $parsed['scheme'].'://'.$parsed['host'].$redirect
                : $redirect;

            return $this->resolve($redirectUrl);
        }

        if (! preg_match('~^/maps/org/[^/]+/(\d+)(?:/|$)~', $parsed['path'], $matches)) {
            throw new InvalidArgumentException('The Yandex Maps URL has no business id.');
        }

        return [
            'url' => $normalized,
            'business_id' => $matches[1],
        ];
    }

    /**
     * @return array{scheme: string, host: string, path: string}
     */
    private function parseUrl(string $url): array
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            throw new InvalidArgumentException('The Yandex Maps URL is invalid.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $path = '/'.ltrim($parts['path'], '/');

        if (! in_array($scheme, ['http', 'https'], true) || ! $this->isYandexHost($host)) {
            throw new InvalidArgumentException('The URL must point to Yandex Maps.');
        }

        if (! str_starts_with($path, '/maps/')) {
            throw new InvalidArgumentException('The URL path must start with /maps/.');
        }

        return compact('scheme', 'host', 'path');
    }

    /**
     * @param  array{scheme: string, host: string, path: string}  $parts
     */
    private function normalize(array $parts): string
    {
        return rtrim($parts['scheme'].'://'.$parts['host'].'/'.ltrim($parts['path'], '/'), '/').'/';
    }

    private function isYandexHost(string $host): bool
    {
        return (bool) preg_match('/(^|\.)yandex\.[a-z]{2,}$/i', $host);
    }
}
