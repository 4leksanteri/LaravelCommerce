<?php

declare(strict_types=1);

namespace App\Actions\Sellers;

use App\Enums\Currency;
use App\Enums\SellerStatus;
use App\Exceptions\ShopApplicationNotAllowedException;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opens a shop application, or resubmits a rejected one.
 *
 * The rules about *when* an application may be made live here rather than in
 * the controller, because they are domain rules: one shop per account, one
 * application at a time, and a rejected application may be sent again.
 *
 * They are also not authorization. Whether somebody may apply at all is
 * `SellerPolicy`'s question and the answer is yes; whether they may apply
 * *right now* depends on what they have already done, and that is a conflict
 * rather than a refusal. See ShopApplicationNotAllowedException.
 */
final class ApplyToSell
{
    /**
     * @param  array{shop_name: string, description?: string|null, contact_email: string, currency: string}  $attributes
     *
     * @throws ShopApplicationNotAllowedException
     */
    public function handle(User $user, array $attributes): Seller
    {
        try {
            return DB::transaction(function () use ($user, $attributes): Seller {
                // Locked, so that two applications arriving together are
                // serialised rather than both reading "no shop yet".
                $seller = $user->seller()->lockForUpdate()->first();

                if (! $seller instanceof Seller) {
                    return $this->open($user, $attributes);
                }

                if (! $seller->status->isReviewed()) {
                    throw ShopApplicationNotAllowedException::alreadyPending();
                }

                if ($seller->isPublic()) {
                    throw ShopApplicationNotAllowedException::alreadyApproved();
                }

                return $this->resubmit($seller, $attributes);
            });
        } catch (UniqueConstraintViolationException) {
            // Two applications for an account that had none: the lock above
            // holds no rows, so both passed the check and the unique index on
            // sellers.user_id refused the second insert. A double-clicked
            // form is the everyday cause, and it deserves the same answer as
            // applying twice deliberately rather than a 500.
            throw ShopApplicationNotAllowedException::alreadyPending();
        }
    }

    /**
     * @param  array{shop_name: string, description?: string|null, contact_email: string, currency: string}  $attributes
     */
    private function open(User $user, array $attributes): Seller
    {
        // forceFill, not create(). `user_id`, `slug`, `currency`, `status` and
        // `applied_at` are all absent from the model's fillable list, because
        // none of them is a field anybody submits - and mass assignment would
        // have dropped every one of them silently, leaving an insert with five
        // nulls in it.
        //
        // This action is trusted to set them; a request body is not. That is
        // the distinction the fillable list exists to draw.
        $seller = new Seller;

        $seller->forceFill([
            'user_id' => $user->id,
            'shop_name' => $attributes['shop_name'],
            'slug' => $this->uniqueSlug($attributes['shop_name']),
            'description' => $attributes['description'] ?? null,
            'contact_email' => $attributes['contact_email'],
            'currency' => Currency::from($attributes['currency']),
            'status' => SellerStatus::Pending,
            'applied_at' => now(),
        ])->save();

        return $seller;
    }

    /**
     * @param  array{shop_name: string, description?: string|null, contact_email: string, currency: string}  $attributes
     */
    private function resubmit(Seller $seller, array $attributes): Seller
    {
        $seller->forceFill([
            'shop_name' => $attributes['shop_name'],
            'description' => $attributes['description'] ?? null,
            'contact_email' => $attributes['contact_email'],

            // Back to the queue, and the previous decision cleared with it.
            // Leaving reviewed_at set would violate the table's own check
            // constraint, which says a pending row has not been reviewed.
            'status' => SellerStatus::Pending,
            'rejection_reason' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'applied_at' => now(),
        ])->save();

        // The slug and the currency are deliberately not touched. The slug is
        // the shop's address and moving it breaks saved links; the currency is
        // fixed at first application (ADR 0007). A resubmission is the same
        // shop trying again, not a new one.

        return $seller;
    }

    /**
     * A readable, stable address for the shop.
     *
     * Uniqueness is settled by asking the database rather than by trusting the
     * name to be unique. The suffix counts up rather than using a random
     * token, because a person reads this in a URL and `bake-house-2` is
     * something they can say out loud.
     *
     * The loop is inside the caller's transaction, which is what makes the
     * check and the insert atomic. The unique index is still the real
     * guarantee - two applications racing outside a transaction would both see
     * the slug free, and the second insert is the one that fails.
     */
    private function uniqueSlug(string $shopName): string
    {
        $base = Str::slug($shopName);

        if ($base === '') {
            // A name written entirely in a script Str::slug cannot transliterate
            // leaves nothing behind. The shop still needs an address.
            $base = 'shop';
        }

        $slug = $base;
        $suffix = 1;

        while (Seller::where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
