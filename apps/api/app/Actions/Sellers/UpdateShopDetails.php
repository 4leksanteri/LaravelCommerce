<?php

declare(strict_types=1);

namespace App\Actions\Sellers;

use App\Models\Seller;

/**
 * Edits the parts of a shop its owner may change at any time.
 *
 * Three fields, and the omissions matter more than the inclusions:
 *
 *   slug        the shop's public address. Moving it breaks every saved link.
 *   currency    fixed at application (ADR 0007). Changing it would re-price
 *               every product and make placed orders ambiguous.
 *   status      a decision the platform records, not something a shop sets
 *               about itself.
 *
 * Editing details does **not** send an approved shop back for review. A
 * corrected typo in a description is not a reason to take a trading shop
 * offline. If a shop later needs re-review for a substantive change, that is a
 * rule about which fields are substantive, and it is not this action.
 */
final class UpdateShopDetails
{
    /**
     * @param  array{shop_name?: string, description?: string|null, contact_email?: string}  $attributes
     */
    public function handle(Seller $seller, array $attributes): Seller
    {
        $seller->fill($attributes)->save();

        return $seller;
    }
}
