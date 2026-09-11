<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ShopApplicationBlocker;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The API representation of a user.
 *
 * A resource is an allowlist, not a filter: every field a client receives is
 * named here. Returning a model directly would publish whatever columns the
 * table happens to have today, which is how a password hash or an internal
 * flag reaches a browser after an unrelated migration.
 */
final class UserResource extends JsonResource
{
    public function __construct(private readonly User $user)
    {
        parent::__construct($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Two answers below read the shop, so it is loaded once rather than
        // asked about twice.
        $this->user->loadMissing('seller');

        return [
            'id' => $this->user->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'email_verified_at' => $this->user->email_verified_at?->toIso8601String(),
            'created_at' => $this->user->created_at?->toIso8601String(),

            // The answer, not the role. The frontend draws an admin area from
            // this; handing it `role: "staff"` instead would make it re-derive
            // the rule, and the copy in the browser is the one that goes stale
            // and the one an attacker controls. Root CLAUDE.md section 4.
            //
            // `role` itself is deliberately not published. Nothing outside the
            // API needs to know how the platform models its own staff.
            'can_review_sellers' => $this->user->isPlatformStaff(),

            // Which of "Sell with us" and "Your shop" the header offers. The
            // frontend could not answer this at all before: its only route to
            // it was calling /seller speculatively and reading a 403 as "no",
            // which is a permission failure used as a question.
            'has_shop' => $this->user->hasShop(),

            // Whether the application form can be sent, and if not, what is in
            // the way. The frontend draws the next step from this rather than
            // reading `email_verified_at` and the shop's status (ADR 0033).
            'shop_application_blocker' => $this->shopApplicationBlocker(),
        ];
    }

    /**
     * Why this person cannot apply to sell right now, or null when they can.
     *
     * The conditions the application refuses on, in the order it refuses them:
     * the `verified` middleware first, then ApplyToSell. ShopApplicationBlocker
     * Test holds this answer and that behaviour to each other.
     */
    private function shopApplicationBlocker(): ?ShopApplicationBlocker
    {
        if (! $this->user->hasVerifiedEmail()) {
            return ShopApplicationBlocker::UnverifiedEmail;
        }

        $seller = $this->user->seller;

        if (! $seller instanceof Seller) {
            return null;
        }

        if (! $seller->status->isReviewed()) {
            return ShopApplicationBlocker::AwaitingReview;
        }

        return $seller->isPublic() ? ShopApplicationBlocker::AlreadyOpen : null;
    }
}
