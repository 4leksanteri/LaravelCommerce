<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Seller;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shop, as its owner or a reviewer sees it.
 *
 * Not what a shopper sees - that is `PublicShopResource`, and the difference
 * is deliberate. Whether an application was rejected, and what was said about
 * it, is between the applicant and the platform.
 */
final class SellerResource extends JsonResource
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
        $viewer = $request->user();

        return [
            'id' => $this->seller->id,
            'shop_name' => $this->seller->shop_name,
            'slug' => $this->seller->slug,
            'description' => $this->seller->description,
            'contact_email' => $this->seller->contact_email,
            // The enum itself, not ->value. PHP serialises a backed enum to
            // its value, so the JSON is identical - but the generator can see
            // the enum and produces a union of the actual cases rather than a
            // bare `string`, which is the difference between the frontend
            // being able to switch on a status and having to guess at it.
            'currency' => $this->seller->currency,
            'status' => $this->seller->status,

            // Only ever set on a rejection - the table has a check constraint
            // saying so - and it is the one thing the applicant needs in order
            // to fix the application and try again.
            'rejection_reason' => $this->seller->rejection_reason,

            'applied_at' => $this->seller->applied_at->toIso8601String(),
            'reviewed_at' => $this->seller->reviewed_at?->toIso8601String(),

            // The answer, not the inputs. The frontend draws an edit form from
            // this rather than re-deriving "am I the owner" from an id
            // comparison it would have to keep in step with the policy. Root
            // CLAUDE.md section 4.
            // Through typed methods rather than inline, because the generator
            // reads a declared `: bool` return type and cannot resolve what
            // Gate::can() gives back - inline, both of these were published to
            // the frontend as `string`.
            'can_edit' => $this->canEdit($viewer),
            'can_review' => $this->canReview($viewer),

            // Also an answer: whether shoppers can see this shop. Read off the
            // status by one method, so nothing anywhere decides it a second
            // way and disagrees.
            'is_public' => $this->seller->isPublic(),
        ];
    }

    private function canEdit(?Authenticatable $viewer): bool
    {
        return $viewer instanceof User && $viewer->can('update', $this->seller);
    }

    private function canReview(?Authenticatable $viewer): bool
    {
        return $viewer instanceof User && $viewer->can('review', $this->seller);
    }
}
