<?php

namespace Database\Factories;

use App\Models\Prospect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prospect>
 */
class ProspectFactory extends Factory
{
    protected $model = Prospect::class;

    public function definition(): array
    {
        return [
            'company' => fake()->unique()->company(),
            'family' => fake()->randomElement(['institution', 'grande', 'moyenne', 'petite']),
            'sector' => 'Industrie',
            'locality' => 'Lausanne',
            'reasons' => 'Croissance récente et besoin de structurer la communication.',
            'channel' => 'email',
            'relevance' => 2,
        ];
    }
}
