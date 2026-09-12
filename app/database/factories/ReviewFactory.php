<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'external_id' => (string) fake()->unique()->uuid(),
            'author' => fake()->name(),
            'rating' => fake()->numberBetween(1, 5),
            'text' => fake()->paragraph(),
            'published_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
