<?php

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function mapsOrganization(): Organization
{
    return new Organization([
        'url' => 'https://yandex.ru/maps/org/cafe/1234567890/',
        'business_id' => '1234567890',
    ]);
}

function mapsPage(array $reviews, array $params = []): array
{
    $count = $params['count'] ?? count($reviews);

    return [
        'data' => [
            'reviews' => $reviews,
            'params' => array_merge([
                'offset' => 0,
                'limit' => 50,
                'count' => $count,
                'loadedReviewsCount' => count($reviews),
                'page' => 1,
                'totalPages' => 1,
                'reviewsRemained' => max(0, $count - count($reviews)),
            ], $params),
        ],
    ];
}

function mapsReview(string $id, string $author, int $rating, string $text, ?string $publishedAt = null): array
{
    return [
        'reviewId' => $id,
        'businessId' => '1234567890',
        'author' => ['name' => $author],
        'rating' => $rating,
        'text' => $text,
        'updatedTime' => $publishedAt ?? '2026-08-01T12:30:00Z',
    ];
}
