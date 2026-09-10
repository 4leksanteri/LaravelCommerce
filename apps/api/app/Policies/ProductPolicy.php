<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Who may do what to a listing.
 *
 * Short, because most of the ownership rule is carried by the query rather
 * than restated here: seller endpoints resolve products through
 * `$seller->products()`, so another shop's product is never loaded in the
 * first place (ADR 0008). These methods are what `ProductResource` asks in
 * order to tell the frontend what to draw, and the belt to that braces.
 *
 * Staff are absent on purpose. Reviewing a shop is not editing its catalogue,
 * and nothing here gives platform staff a way to rewrite somebody's listings.
 */
final class ProductPolicy
{
    public function update(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    /**
     * Whether this person may put the listing on sale.
     *
     * Ownership only. Whether the *shop* is approved is a different question
     * with a different answer - it is a fact about the world rather than about
     * the person, so it is a 409 from PublishProduct and not a 403 from here.
     * See ADR 0008.
     */
    public function publish(User $user, Product $product): bool
    {
        return $this->owns($user, $product);
    }

    private function owns(User $user, Product $product): bool
    {
        $seller = $user->seller;

        return $seller !== null && $seller->id === $product->seller_id;
    }
}
