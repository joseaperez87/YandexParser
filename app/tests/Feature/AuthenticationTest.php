<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->withHeader('Origin', 'http://localhost');
});

it('logs in with valid credentials and exposes the authenticated user', function (): void {
    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $this->postJson('/api/login', [
        'email' => 'ADMIN@EXAMPLE.COM',
        'password' => 'password',
    ])->assertNoContent();

    $this->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('email', $user->email);
});

it('rejects invalid credentials without authenticating the user', function (): void {
    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $this->postJson('/api/login', [
        'email' => 'admin@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['email']]);

    $this->getJson('/api/user')->assertUnauthorized();
});

it('rejects unauthenticated access to the current user endpoint', function (): void {
    $this->getJson('/api/user')->assertUnauthorized();
});

it('logs out and invalidates the authenticated session', function (): void {
    User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $this->postJson('/api/login', [
        'email' => 'admin@example.com',
        'password' => 'password',
    ])->assertNoContent();

    $this->postJson('/api/logout')->assertNoContent();

    $this->assertGuest();
});

it('seeds the configured user idempotently', function (): void {
    Artisan::call('db:seed', [
        '--class' => DatabaseSeeder::class,
        '--no-interaction' => true,
    ]);
    Artisan::call('db:seed', [
        '--class' => DatabaseSeeder::class,
        '--no-interaction' => true,
    ]);

    expect(User::where('email', env('SEED_USER_EMAIL', 'admin@example.com'))->count())->toBe(1);
});
