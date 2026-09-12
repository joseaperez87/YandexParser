<?php

declare(strict_types=1);

namespace App\Services\YandexMaps\Strategies;

use App\Models\Organization;
use App\Services\YandexMaps\Contracts\ParserStrategy;
use App\Services\YandexMaps\Exceptions\BlockedException;
use App\Services\YandexMaps\Exceptions\EmptyResponseException;
use App\Services\YandexMaps\Exceptions\ParsingException;
use App\Services\YandexMaps\Exceptions\SourceChangedException;
use App\Services\YandexMaps\ParseContext;
use App\Services\YandexMaps\ParserResult;
use App\Services\YandexMaps\Support\CardMetadata;
use App\Services\YandexMaps\Support\RequestSigner;
use App\Services\YandexMaps\Support\ReviewNormalizer;
use App\Services\YandexMaps\UrlResolver;
use App\Support\AntiBot\ProxyPool;
use App\Support\AntiBot\Throttle;
use App\Support\AntiBot\UserAgentRotator;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class JsonApiStrategy implements ParserStrategy
{
    public function __construct(
        private readonly UrlResolver $urlResolver,
        private readonly UserAgentRotator $userAgentRotator,
        private readonly Throttle $throttle,
        private readonly ProxyPool $proxyPool,
    ) {}

    public function parse(Organization $organization, ParseContext $context): ParserResult
    {
        $resolved = $this->urlResolver->resolve((string) $organization->url);
        $businessId = $context->businessId ?? $resolved['business_id'];
        $host = (string) parse_url($resolved['url'], PHP_URL_HOST);

        $headers = array_merge([
            'Accept' => 'text/html,application/xhtml+xml,application/json',
            'Accept-Language' => (string) config('yandex.region', 'ru'),
            'User-Agent' => $this->userAgentRotator->next(),
        ], $context->headers);

        $cookies = new CookieJar;

        $pageResponse = $this->request($resolved['url'], $headers, $cookies);
        $metadata = CardMetadata::fromHtml($pageResponse->body());

        $csrfToken = $context->csrfToken ?? $metadata['csrf_token'];

        if ($csrfToken === null) {
            throw new SourceChangedException('Yandex Maps csrf token was not found.');
        }

        $sessionId = $metadata['session_id'];

        if ($sessionId === null) {
            throw new SourceChangedException('Yandex Maps session id was not found.');
        }

        $requestId = $metadata['request_id'] ?? $sessionId;
        $endpoint = $this->endpoint($resolved['url']);
        $pageSize = max(1, (int) config('yandex.page_size', 50));
        $maxReviews = max(1, (int) config('yandex.max_reviews', 600));
        $maxPages = max(1, (int) config('yandex.max_pages', 12));
        $ranking = (string) config('yandex.ranking', 'by_relevance_org');
        $locale = (string) config('yandex.locale', 'ru_RU');

        $reviews = [];
        $seen = [];
        $sourceReviewsCount = $metadata['reviews_count'];
        $page = max(1, $context->page);
        $status = 'ready';

        while (count($reviews) < $maxReviews) {
            $data = $this->fetchPage($endpoint, $cookies, $headers, $resolved['url'], [
                'businessId' => $businessId,
                'page' => $page,
                'pageSize' => $pageSize,
                'reqId' => $requestId,
                'ranking' => $ranking,
                'locale' => $locale,
                'sessionId' => $sessionId,
                'ajax' => '1',
            ], $csrfToken);

            $pageReviews = is_array($data['reviews'] ?? null) ? $data['reviews'] : [];
            $params = is_array($data['params'] ?? null) ? $data['params'] : [];

            if ($sourceReviewsCount === null && isset($params['count'])) {
                $sourceReviewsCount = (int) $params['count'];
            }

            if ($pageReviews === [] && ($sourceReviewsCount ?? 0) > 0 && $reviews === []) {
                throw new EmptyResponseException('Yandex Maps returned no reviews for a non-empty organization.');
            }

            foreach ($pageReviews as $review) {
                $normalized = ReviewNormalizer::review($review);

                if ($normalized['external_id'] === null || isset($seen[$normalized['external_id']])) {
                    continue;
                }

                $seen[$normalized['external_id']] = true;
                $reviews[] = $normalized;

                if (count($reviews) >= $maxReviews) {
                    break;
                }
            }

            if ($context->onProgress !== null) {
                ($context->onProgress)(count($reviews), $sourceReviewsCount ?? count($reviews));
            }

            $totalPages = isset($params['totalPages']) ? (int) $params['totalPages'] : null;
            $lastPage = $totalPages !== null ? min($totalPages, $maxPages) : $maxPages;

            if ($pageReviews === [] || $page >= $lastPage) {
                break;
            }

            $page++;
            $this->throttle->wait();
        }

        if ($sourceReviewsCount !== null && $sourceReviewsCount > count($reviews) && count($reviews) < $maxReviews) {
            $status = 'partial';
        }

        return new ParserResult(
            reviews: $reviews,
            rating: $metadata['rating'],
            ratingsCount: $metadata['ratings_count'] ?? 0,
            reviewsCount: $sourceReviewsCount ?? count($reviews),
            status: $status,
            warnings: $status === 'partial' ? ['The collected review count differs from the source aggregate.'] : [],
            businessId: $businessId,
            title: $metadata['title'],
            address: $metadata['address'],
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(string $url, array $headers, CookieJar $cookies): Response
    {
        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) config('yandex.timeout', 15))
                ->withOptions(['verify' => config('yandex.verify'), 'cookies' => $cookies, 'proxy' => $this->proxyPool->next()])
                ->get($url);
        } catch (Throwable $exception) {
            throw new ParsingException('Yandex Maps page request failed.', 0, $exception);
        }

        $this->guardResponse($response);

        return $response;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private function fetchPage(string $endpoint, CookieJar $cookies, array $headers, string $retpath, array $params, string &$csrfToken): array
    {
        $headers = array_merge($headers, [
            'Accept' => 'application/json',
            'X-Retpath-Y' => $retpath,
        ]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $params['csrfToken'] = $csrfToken;
            $params['s'] = RequestSigner::sign($params);

            try {
                $response = Http::withHeaders($headers)
                    ->timeout((int) config('yandex.timeout', 15))
                    ->withOptions(['verify' => config('yandex.verify'), 'cookies' => $cookies, 'proxy' => $this->proxyPool->next()])
                    ->get($endpoint, $params);
            } catch (Throwable $exception) {
                throw new ParsingException('Yandex Maps reviews request failed.', 0, $exception);
            }

            $this->guardResponse($response);

            $json = $response->json();

            if (! is_array($json)) {
                throw new SourceChangedException('Yandex Maps reviews response is not JSON.');
            }

            if (($json['type'] ?? null) === 'captcha') {
                throw new BlockedException('Yandex Maps returned a captcha.');
            }

            if (isset($json['error'])) {
                $message = is_array($json['error'])
                    ? (string) ($json['error']['message'] ?? 'unknown error')
                    : (string) $json['error'];

                throw new ParsingException('Yandex Maps reviews error: '.$message);
            }

            if (isset($json['csrfToken']) && ! isset($json['data'])) {
                $csrfToken = (string) $json['csrfToken'];

                continue;
            }

            ReviewNormalizer::assertData($json);

            return $json['data'];
        }

        throw new BlockedException('Yandex Maps rejected the csrf token.');
    }

    private function guardResponse(Response $response): void
    {
        if (in_array($response->status(), [403, 429], true)) {
            throw new BlockedException('Yandex Maps blocked the request (HTTP '.$response->status().').');
        }

        if ($this->isCaptchaChallenge($response)) {
            throw new BlockedException('Yandex Maps returned a captcha.');
        }

        if ($response->failed()) {
            throw new ParsingException('Yandex Maps returned HTTP '.$response->status().'.');
        }
    }

    private function isCaptchaChallenge(Response $response): bool
    {
        $location = strtolower((string) $response->header('Location'));

        if ($location !== '' && str_contains($location, 'captcha')) {
            return true;
        }

        $effectiveUri = strtolower((string) $response->effectiveUri());

        if ($effectiveUri !== '' && str_contains($effectiveUri, 'captcha')) {
            return true;
        }

        $body = strtolower($response->body());

        foreach (['showcaptcha', 'smart-captcha', 'smartcaptcha', 'captcha-page', 'are you a robot'] as $marker) {
            if (str_contains($body, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function endpoint(string $cardUrl): string
    {
        $configured = (string) config('yandex.reviews_endpoint');

        if (str_starts_with($configured, 'http')) {
            return $configured;
        }

        return (string) parse_url($cardUrl, PHP_URL_SCHEME).'://'.parse_url($cardUrl, PHP_URL_HOST).'/'.ltrim($configured, '/');
    }
}
