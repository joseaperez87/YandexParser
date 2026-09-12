<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $businessId = (string) fake()->unique()->numberBetween(1000000000, 9999999999);

        return [
            'user_id' => User::factory(),
            'url' => "https://yandex.ru/maps/org/test/{$businessId}/",
            'business_id' => $businessId,
            'title' => fake()->company(),
            'address' => fake()->address(),
            'rating' => fake()->randomFloat(1, 1, 5),
            'ratings_count' => fake()->numberBetween(10, 1000),
            'reviews_count' => fake()->numberBetween(0, 100),
            'parse_status' => 'ready',
            'parse_error' => null,
            'last_parsed_at' => now(),
        ];
    }
}
