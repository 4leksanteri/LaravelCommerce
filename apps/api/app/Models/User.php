<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * `MustVerifyEmail` is what makes Laravel send a verification notification and
 * what the `verified` middleware checks. It does not, on its own, stop an
 * unverified user doing anything: that is the middleware's job, applied per
 * route. See routes/api/v1.php.
 *
 * `role` is absent from the fillable list below on purpose. It is not a field
 * anybody submits, and a registration payload carrying `"role": "admin"` must
 * not be able to grant itself one.
 *
 * @property-read Seller|null $seller
 * @property-read Cart|null $cart
 * @property-read Collection<int, Order> $orders
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The column has a default, and that is not enough on its own.
     *
     * A database default applies to the row; it does not reach the model
     * instance that was just created, so `User::create([...])->role` is null
     * until something reloads it. `UserResource` asks the role whether it is
     * staff, so without this line registration returned a 500 on the call
     * that creates the account.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => UserRole::Customer->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * The shop this person runs, if they run one.
     *
     * HasOne rather than HasMany: one shop per account (ADR 0007). The unique
     * constraint on sellers.user_id is what makes that true of the data.
     *
     * @return HasOne<Seller, $this>
     */
    public function seller(): HasOne
    {
        return $this->hasOne(Seller::class);
    }

    /**
     * This person's basket, if they have started one.
     *
     * HasOne, and null until they add something: one cart per account
     * (ADR 0010), and a GET of an empty cart does not create a row. The unique
     * constraint on carts.user_id is what makes "one" true of the data rather
     * than of the code that happens to create them.
     *
     * @return HasOne<Cart, $this>
     */
    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    /**
     * What this person has bought, from every shop.
     *
     * A basket spanning three shops became three orders (ADR 0011), so this is
     * HasMany even for a single checkout. Buyer-side only: a seller's view of
     * the orders placed with their shop reads `Seller`, and is not built yet.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Whether this person acts for the platform rather than for themselves. */
    public function isPlatformStaff(): bool
    {
        return $this->role->isPlatformStaff();
    }
}
