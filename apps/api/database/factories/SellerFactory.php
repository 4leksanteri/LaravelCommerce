<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\SellerStatus;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Seller>
 */
class SellerFactory extends Factory
{
    protected $model = Seller::class;

    /**
     * A pending application, because that is where every shop starts. The
     * states below move it on, and each of them keeps the table's own check
     * constraints satisfied - a reviewed row has a reviewed_at, a rejected one
     * has a reason.
     *
     * @return array<model-property<Seller>, mixed>
     */
    public function definition(): array
    {
        $shopName = fake()->unique()->company();

        return [
            'user_id' => User::factory(),
            'shop_name' => $shopName,
            'slug' => Str::slug($shopName).'-'.Str::lower(Str::random(6)),
            'description' => fake()->paragraph(),
            'contact_email' => fake()->unique()->safeEmail(),
            'currency' => fake()->randomElement(Currency::cases()),
            'status' => SellerStatus::Pending,
            'rejection_reason' => null,
            'applied_at' => now(),
            'reviewed_at' => null,
            'reviewed_by' => null,
        ];
    }

    public function approved(?User $reviewer = null): static
    {
        return $this->state(fn (): array => [
            'status' => SellerStatus::Approved,
            'rejection_reason' => null,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer instanceof User ? $reviewer->id : User::factory()->staff(),
        ]);
    }

    public function rejected(string $reason = 'The shop name is already in use by another seller.'): static
    {
        return $this->state(fn (): array => [
            'status' => SellerStatus::Rejected,
            'rejection_reason' => $reason,
            'reviewed_at' => now(),
            'reviewed_by' => User::factory()->staff(),
        ]);
    }
}
