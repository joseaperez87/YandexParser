<?php

use App\Jobs\ParseOrganizationJob;
use App\Models\Organization;
use App\Models\ParseRun;
use App\Models\Review;
use App\Models\User;
use App\Services\YandexMaps\Exceptions\EmptyResponseException;
use App\Services\YandexMaps\Exceptions\ParsingException;
use App\Services\YandexMaps\Exceptions\SourceChangedException;
use App\Services\YandexMaps\ParseContext;
use App\Services\YandexMaps\ParserResult;
use App\Services\YandexMaps\YandexMapsParser;
use Mockery\MockInterface;

function makeOrganization(array $attributes = []): Organization
{
    $user = User::factory()->create();

    return Organization::create(array_merge([
        'user_id' => $user->id,
        'url' => 'https://yandex.ru/maps/org/cafe/1234567890/',
        'business_id' => '1234567890',
    ], $attributes));
}

function parserResult(array $overrides = []): ParserResult
{
    $reviews = $overrides['reviews'] ?? [
        ['external_id' => 'r-1', 'author' => 'Иван', 'rating' => 5, 'text' => 'Отлично', 'published_at' => '2026-08-01T12:30:00Z'],
        ['external_id' => 'r-2', 'author' => 'Ольга', 'rating' => 4, 'text' => 'Хорошо', 'published_at' => '2026-08-02T12:30:00Z'],
    ];

    return new ParserResult(
        reviews: $reviews,
        rating: $overrides['rating'] ?? 4.6,
        ratingsCount: $overrides['ratingsCount'] ?? 812,
        reviewsCount: $overrides['reviewsCount'] ?? count($reviews),
        status: $overrides['status'] ?? 'ready',
        businessId: '1234567890',
        title: $overrides['title'] ?? 'Кафе Пушкин',
        address: $overrides['address'] ?? 'г. Москва, ул. Тверская, 1',
    );
}

function mockParser(ParserResult|Throwable $result): YandexMapsParser
{
    return Mockery::mock(YandexMapsParser::class, function (MockInterface $mock) use ($result): void {
        $expectation = $mock->shouldReceive('parse');

        if ($result instanceof Throwable) {
            $expectation->andThrow($result);
        } else {
            $expectation->andReturn($result);
        }
    });
}

it('persists reviews, aggregates and a snapshot and marks the run done', function (): void {
    $organization = makeOrganization();

    (new ParseOrganizationJob($organization))->handle(mockParser(parserResult()));

    expect($organization->fresh()->parse_status)->toBe('ready')
        ->and($organization->fresh()->rating)->toBe(4.6)
        ->and($organization->fresh()->ratings_count)->toBe(812)
        ->and($organization->fresh()->reviews_count)->toBe(2)
        ->and($organization->fresh()->title)->toBe('Кафе Пушкин')
        ->and($organization->fresh()->address)->toBe('г. Москва, ул. Тверская, 1')
        ->and(Review::where('organization_id', $organization->id)->count())->toBe(2)
        ->and($organization->snapshots()->count())->toBe(1)
        ->and($organization->parseRuns()->latest('id')->first()->status)->toBe('done');
});

it('does not duplicate reviews on a repeated parse', function (): void {
    $organization = makeOrganization();

    (new ParseOrganizationJob($organization))->handle(mockParser(parserResult()));
    (new ParseOrganizationJob($organization))->handle(mockParser(parserResult()));

    expect(Review::where('organization_id', $organization->id)->count())->toBe(2);
});

it('deletes reviews that are no longer returned on a complete parse', function (): void {
    $organization = makeOrganization();
    Review::create([
        'organization_id' => $organization->id,
        'external_id' => 'old',
        'author' => 'Старый',
        'rating' => 3,
        'text' => null,
    ]);

    (new ParseOrganizationJob($organization))->handle(mockParser(parserResult()));

    expect(Review::where('organization_id', $organization->id)->where('external_id', 'old')->exists())->toBeFalse()
        ->and(Review::where('organization_id', $organization->id)->count())->toBe(2);
});

it('keeps orphan reviews when the parse is partial', function (): void {
    $organization = makeOrganization();
    Review::create([
        'organization_id' => $organization->id,
        'external_id' => 'old',
        'author' => 'Старый',
        'rating' => 3,
        'text' => null,
    ]);

    (new ParseOrganizationJob($organization))->handle(mockParser(parserResult([
        'reviews' => [
            ['external_id' => 'r-1', 'author' => 'Иван', 'rating' => 5, 'text' => 'Отлично', 'published_at' => null],
        ],
        'reviewsCount' => 5,
        'status' => 'partial',
    ])));

    expect(Review::where('organization_id', $organization->id)->where('external_id', 'old')->exists())->toBeTrue()
        ->and(Review::where('organization_id', $organization->id)->count())->toBe(2)
        ->and($organization->fresh()->parse_status)->toBe('partial');
});

it('updates parse run progress through the parser callback', function (): void {
    $organization = makeOrganization();

    $parser = Mockery::mock(YandexMapsParser::class, function (MockInterface $mock): void {
        $mock->shouldReceive('parse')->once()->andReturnUsing(function (Organization $org, ParseContext $context): ParserResult {
            ($context->onProgress)(1, 2);

            $run = ParseRun::where('organization_id', $org->id)->latest('id')->first();

            expect($run->status)->toBe('running')
                ->and($run->progress)->toBe(1)
                ->and($run->total)->toBe(2);

            return parserResult();
        });
    });

    (new ParseOrganizationJob($organization))->handle($parser);
});

it('records parse changes between snapshots', function (): void {
    $organization = makeOrganization();

    (new ParseOrganizationJob($organization))->handle(mockParser(parserResult(['rating' => 4.6])));
    (new ParseOrganizationJob($organization))->handle(mockParser(parserResult(['rating' => 4.8])));

    $change = $organization->parseChanges()->where('field', 'rating')->first();

    expect($change)->not->toBeNull()
        ->and($change->old)->toBe('4.6')
        ->and($change->new)->toBe('4.8');
});

it('marks the run as source_changed without retrying', function (): void {
    $organization = makeOrganization();

    (new ParseOrganizationJob($organization))->handle(mockParser(new SourceChangedException('schema changed')));

    $run = $organization->parseRuns()->latest('id')->first();

    expect($run->status)->toBe('source_changed')
        ->and($organization->fresh()->parse_status)->toBe('source_changed')
        ->and($organization->fresh()->parse_error)->toBe('schema changed');
});

it('marks the run as empty when the source returns no reviews', function (): void {
    $organization = makeOrganization();

    (new ParseOrganizationJob($organization))->handle(mockParser(new EmptyResponseException('no reviews')));

    expect($organization->parseRuns()->latest('id')->first()->status)->toBe('empty')
        ->and($organization->fresh()->parse_status)->toBe('empty');
});

it('lets transient failures bubble up for retries', function (): void {
    $organization = makeOrganization();

    expect(fn () => (new ParseOrganizationJob($organization))->handle(mockParser(new ParsingException('timeout'))))
        ->toThrow(ParsingException::class);
});

it('marks the organization and run as failed when the job fails', function (): void {
    $organization = makeOrganization();
    $run = $organization->parseRuns()->create(['status' => 'running', 'started_at' => now()]);

    (new ParseOrganizationJob($organization))->failed(new ParsingException('boom'));

    expect($organization->fresh()->parse_status)->toBe('failed')
        ->and($organization->fresh()->parse_error)->toBe('boom')
        ->and($run->fresh()->status)->toBe('failed');
});

it('configures retries and exponential backoff', function (): void {
    $job = new ParseOrganizationJob(makeOrganization());

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 60, 300]);
});
