<?php

use App\Jobs\ParseOrganizationJob;
use App\Models\Organization;
use App\Models\ParseRun;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function organizationApiUser(): User
{
    return User::factory()->create();
}

it('requires authentication for every organization endpoint', function (): void {
    $this->getJson('/api/organization')->assertUnauthorized();
    $this->postJson('/api/organization', ['url' => 'https://yandex.ru/maps/org/cafe/1/'])->assertUnauthorized();
    $this->postJson('/api/organization/refresh')->assertUnauthorized();
    $this->getJson('/api/organization/parse-status')->assertUnauthorized();
    $this->getJson('/api/reviews')->assertUnauthorized();
});

it('returns null data when the user has no organization', function (): void {
    $this->actingAs(organizationApiUser(), 'sanctum')
        ->getJson('/api/organization')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('stores a valid organization url and queues parsing', function (): void {
    Queue::fake();
    $user = organizationApiUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/organization', [
            'url' => 'https://yandex.ru/maps/org/cafe/1234567890/',
        ])
        ->assertCreated()
        ->assertJsonPath('data.business_id', '1234567890')
        ->assertJsonPath('data.url', 'https://yandex.ru/maps/org/cafe/1234567890/')
        ->assertJsonPath('data.parse_status', 'queued');

    expect(Organization::where('user_id', $user->id)->count())->toBe(1);
    Queue::assertPushed(ParseOrganizationJob::class);
});

it('rejects a url from a non-yandex host', function (): void {
    Queue::fake();
    $user = organizationApiUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/organization', [
            'url' => 'https://google.com/maps/org/cafe/1234567890/',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['url']]);

    expect(Organization::where('user_id', $user->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects a yandex url without the maps path', function (): void {
    $user = organizationApiUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/organization', [
            'url' => 'https://yandex.ru/search/1234567890',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['url']]);

    expect(Organization::where('user_id', $user->id)->count())->toBe(0);
});

it('resolves a short yandex maps url to the canonical one', function (): void {
    Queue::fake();
    $user = organizationApiUser();

    Http::fake([
        'https://yandex.ru/maps/-/*' => Http::response('', 302, [
            'Location' => 'https://yandex.ru/maps/org/cafe/1234567890/',
        ]),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/organization', [
            'url' => 'https://yandex.ru/maps/-/CDqabc',
        ])
        ->assertCreated()
        ->assertJsonPath('data.business_id', '1234567890')
        ->assertJsonPath('data.url', 'https://yandex.ru/maps/org/cafe/1234567890/');

    Queue::assertPushed(ParseOrganizationJob::class);
});

it('updates the existing organization instead of creating a duplicate', function (): void {
    Queue::fake();
    $user = organizationApiUser();
    Organization::factory()->for($user)->create([
        'url' => 'https://yandex.ru/maps/org/old/1111111111/',
        'business_id' => '1111111111',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/organization', [
            'url' => 'https://yandex.ru/maps/org/new/2222222222/',
        ])
        ->assertOk()
        ->assertJsonPath('data.business_id', '2222222222');

    expect(Organization::where('user_id', $user->id)->count())->toBe(1)
        ->and(Organization::where('user_id', $user->id)->first()->business_id)->toBe('2222222222');
});

it('exposes the organization with separate aggregates', function (): void {
    $user = organizationApiUser();
    Organization::factory()->for($user)->create([
        'rating' => 4.6,
        'ratings_count' => 812,
        'reviews_count' => 604,
        'title' => 'Кафе Пушкин',
        'address' => 'г. Москва, ул. Тверская, 1',
        'parse_status' => 'ready',
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/organization')
        ->assertOk()
        ->assertJsonPath('data.rating', 4.6)
        ->assertJsonPath('data.ratings_count', 812)
        ->assertJsonPath('data.reviews_count', 604)
        ->assertJsonPath('data.title', 'Кафе Пушкин')
        ->assertJsonPath('data.address', 'г. Москва, ул. Тверская, 1');
});

it('returns 404 when refreshing without a saved organization', function (): void {
    $this->actingAs(organizationApiUser(), 'sanctum')
        ->postJson('/api/organization/refresh')
        ->assertStatus(404);
});

it('queues a new parse when refreshing a ready organization', function (): void {
    Queue::fake();
    $user = organizationApiUser();
    Organization::factory()->for($user)->create(['parse_status' => 'ready']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/organization/refresh')
        ->assertStatus(202)
        ->assertJsonPath('data.parse_status', 'queued');

    Queue::assertPushed(ParseOrganizationJob::class);
});

it('rejects a refresh while a parse is already running', function (): void {
    Queue::fake();
    $user = organizationApiUser();
    $organization = Organization::factory()->for($user)->create(['parse_status' => 'running']);
    $organization->parseRuns()->create(['status' => 'running', 'started_at' => now()]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/organization/refresh')
        ->assertStatus(409);

    Queue::assertNothingPushed();
});

it('reports the latest parse run status', function (): void {
    $user = organizationApiUser();
    $organization = Organization::factory()->for($user)->create(['parse_status' => 'running']);
    ParseRun::create([
        'organization_id' => $organization->id,
        'status' => 'running',
        'progress' => 120,
        'total' => 600,
        'started_at' => now(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/organization/parse-status')
        ->assertOk()
        ->assertJsonPath('data.parse_status', 'running')
        ->assertJsonPath('data.run.status', 'running')
        ->assertJsonPath('data.run.progress', 120)
        ->assertJsonPath('data.run.total', 600);
});

it('resets aggregates and stale reviews when saving a different business', function (): void {
    Queue::fake();
    $user = organizationApiUser();
    $organization = Organization::factory()->for($user)->create([
        'url' => 'https://yandex.ru/maps/org/old/1111111111/',
        'business_id' => '1111111111',
        'title' => 'Старая организация',
        'rating' => 4.2,
        'ratings_count' => 100,
        'reviews_count' => 40,
        'parse_status' => 'ready',
    ]);
    Review::factory()->for($organization, 'organization')->create(['external_id' => 'old-review-1']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/organization', [
            'url' => 'https://yandex.ru/maps/org/new/2222222222/',
        ])
        ->assertOk()
        ->assertJsonPath('data.business_id', '2222222222')
        ->assertJsonPath('data.parse_status', 'queued')
        ->assertJsonPath('data.title', null)
        ->assertJsonPath('data.rating', null)
        ->assertJsonPath('data.ratings_count', 0)
        ->assertJsonPath('data.reviews_count', 0);

    expect(Review::where('organization_id', $organization->id)->count())->toBe(0);
    Queue::assertPushed(ParseOrganizationJob::class);
});

it('preserves aggregates and reviews when saving the same business', function (): void {
    Queue::fake();
    $user = organizationApiUser();
    $organization = Organization::factory()->for($user)->create([
        'url' => 'https://yandex.ru/maps/org/cafe/1234567890/',
        'business_id' => '1234567890',
        'title' => 'Кафе Пушкин',
        'rating' => 4.6,
        'ratings_count' => 812,
        'reviews_count' => 604,
        'parse_status' => 'ready',
    ]);
    Review::factory()->for($organization, 'organization')->create(['external_id' => 'same-review-1']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/organization', [
            'url' => 'https://yandex.ru/maps/org/cafe/1234567890/?ll=1%2C2',
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Кафе Пушкин')
        ->assertJsonPath('data.rating', 4.6)
        ->assertJsonPath('data.parse_status', 'queued');

    expect(Review::where('organization_id', $organization->id)->count())->toBe(1);
});

it('returns null parse status when there is no organization', function (): void {
    $this->actingAs(organizationApiUser(), 'sanctum')
        ->getJson('/api/organization/parse-status')
        ->assertOk()
        ->assertJsonPath('data', null);
});
