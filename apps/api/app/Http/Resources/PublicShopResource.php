<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shop, as anybody browsing the marketplace sees it.
 *
 * An allowlist, and a much shorter one than `SellerResource`. Everything about
 * the review is absent: whether an application was rejected, what was said
 * about it, who decided and when are between the applicant and the platform.
 *
 * There is no `id` either. A public shop is addressed by its slug, and nothing
 * out here needs its primary key.
 */
final class PublicShopResource extends JsonResource
{
    public function __construct(private readonly Seller $seller)
    {
        parent::__construct($seller);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->seller->slug,
            'shop_name' => $this->seller->shop_name,
            'description' => $this->seller->description,

            // Public on purpose: it is how a buyer reaches the shop, and it is
            // the address the seller chose for that, not the one they sign in
            // with.
            'contact_email' => $this->seller->contact_email,

            // A shopper needs to know what they will be charged in before they
            // look at a single price.
            'currency' => $this->seller->currency,
        ];
    }
}
