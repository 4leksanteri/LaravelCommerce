<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    protected $model = Address::class;

    /**
     * @return array<model-property<Address>, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'line1' => fake()->streetAddress(),
            'line2' => null,
            'city' => fake()->city(),
            // Null by default, because for somewhere in the world each of these
            // legitimately is - and a factory that always fills them hides
            // every place the application assumed otherwise.
            'region' => null,
            'postal_code' => fake()->postcode(),
            'country' => 'FI',
            'phone' => null,
        ];
    }
}
