<?php

namespace Database\Factories;

use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opportunity>
 */
class OpportunityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company' => fake()->company(),
            'title' => fake()->jobTitle(),
            'location' => 'Lausanne',
            'rate' => '100%',
            'source' => 'Indeed',
            'url' => fake()->url(),
            'relevance' => fake()->numberBetween(1, 3),
        ];
    }
}
