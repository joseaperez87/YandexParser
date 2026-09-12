<?php

use App\Models\Organization;
use App\Models\Review;
use App\Models\User;

function reviewsApiUser(): User
{
    return User::factory()->create();
}

it('returns an empty page when nothing has been parsed yet', function (): void {
    $this->actingAs(reviewsApiUser(), 'sanctum')
        ->getJson('/api/reviews')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0);
});

it('paginates 50 reviews per page with metadata', function (): void {
    $user = reviewsApiUser();
    $organization = Organization::factory()->for($user)->create();
    Review::factory()->for($organization)->count(60)->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/reviews?page=1')
        ->assertOk()
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 50)
        ->assertJsonPath('meta.total', 60)
        ->assertJsonPath('meta.last_page', 2);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/reviews?page=2')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.current_page', 2);
});

it('sorts reviews by published date descending', function (): void {
    $user = reviewsApiUser();
    $organization = Organization::factory()->for($user)->create();
    Review::factory()->for($organization)->create([
        'external_id' => 'newer',
        'published_at' => '2026-08-02 12:00:00',
    ]);
    Review::factory()->for($organization)->create([
        'external_id' => 'older',
        'published_at' => '2026-08-01 12:00:00',
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/reviews')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'external_id', 'author', 'rating', 'text', 'published_at']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);

    expect($response->json('data.0.external_id'))->toBe('newer')
        ->and($response->json('data.1.external_id'))->toBe('older');
});

it('only returns reviews that belong to the current user organization', function (): void {
    $user = reviewsApiUser();
    $other = reviewsApiUser();

    $organization = Organization::factory()->for($user)->create();
    $foreign = Organization::factory()->for($other)->create();

    Review::factory()->for($organization)->count(3)->create();
    Review::factory()->for($foreign)->count(5)->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/reviews')
        ->assertOk()
        ->assertJsonPath('meta.total', 3);
});

it('validates the per_page cap', function (): void {
    $this->actingAs(reviewsApiUser(), 'sanctum')
        ->getJson('/api/reviews?per_page=200')
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['per_page']]);
});
