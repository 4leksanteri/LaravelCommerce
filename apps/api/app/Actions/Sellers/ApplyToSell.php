<?php

declare(strict_types=1);

namespace App\Actions\Sellers;

use App\Enums\Currency;
use App\Enums\SellerStatus;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opens a shop application.
 *
 * Also handles a second attempt after a rejection: the same row is reused and
 * returned to Pending, so the applicant keeps their slug and their history
 * rather than accumulating a row per attempt.
 */
final class ApplyToSell
{
    /**
     * @param  array{shop_name: string, description?: string|null, contact_email: string, currency: string}  $attributes
     */
    public function handle(User $user, array $attributes): Seller
    {
        return DB::transaction(function () use ($user, $attributes): Seller {
            $seller = $user->seller()->first();

            if ($seller instanceof Seller) {
                return $this->resubmit($seller, $attributes);
            }

            // forceFill, not create(). `user_id`, `slug`, `currency`, `status`
            // and `applied_at` are all absent from the model's fillable list,
            // because none of them is a field anybody submits - and mass
            // assignment would have dropped every one of them silently,
            // leaving an insert with five nulls in it.
            //
            // This action is trusted to set them; a request body is not. That
            // is the distinction the fillable list exists to draw.
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
        });
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
