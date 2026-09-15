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

            /*
             * Why the platform stopped this shop, and null unless it did
             * (ADR 0052). Its own field rather than a reuse of the line above:
             * they are different events at different points in a shop's life,
             * and the table constrains each separately.
             *
             * Who suspended it is recorded and deliberately not published, as a
             * dispute's `resolved_by` is - the decision is the platform's
             * rather than an individual's.
             *
             * @var string|null
             */
            'suspension_reason' => $this->seller->suspension_reason,

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

            // Whether this viewer may stop the shop trading, or let it start
            // again (ADR 0052). Its own policy question rather than a reuse of
            // `can_review`: one is about an application, the other about a
            // business already running.
            'can_suspend' => $this->canSuspend($viewer),

            /*
             * Whether its owner may answer back about being stopped
             * (ADR 0059). Three conditions, and the order is what keeps it
             * cheap: the shop has to be suspended at all, the viewer has to own
             * it, and only then is the database asked whether an appeal is
             * already open.
             *
             * An appeal does not lift the suspension, so this going false is
             * the page's cue to say one is being looked at - not that anything
             * has changed.
             *
             * @var bool
             */
            'can_appeal' => $this->canAppeal($viewer),

            /*
             * Whether one is already waiting.
             *
             * Published beside it rather than left for a page to infer, because
             * `can_appeal` is false both when there is nothing to appeal and
             * when this person already has. A page holding only that field
             * would show the owner of a suspended shop no form, no explanation,
             * and no sign that the argument they sent yesterday ever arrived -
             * which would make "appealing changes nothing" read as "appealing
             * does nothing".
             *
             * @var bool
             */
            'has_open_appeal' => $this->hasOpenAppeal($viewer),

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

    private function canSuspend(?Authenticatable $viewer): bool
    {
        return $viewer instanceof User && $viewer->can('suspend', $this->seller);
    }

    private function canAppeal(?Authenticatable $viewer): bool
    {
        return $this->seller->isSuspended()
            && $viewer instanceof User
            && $viewer->can('update', $this->seller)
            && ! $this->seller->hasOpenAppeal();
    }

    /**
     * Whether this viewer already has an appeal waiting about this shop.
     *
     * Guarded by the same two conditions as `canAppeal`, in the same order, so
     * the two answers cannot disagree about whose appeal is whose and a shop
     * nobody stopped still costs no query. A suspended shop read by its owner
     * asks the database twice, which is a question no other caller pays for.
     */
    private function hasOpenAppeal(?Authenticatable $viewer): bool
    {
        return $this->seller->isSuspended()
            && $viewer instanceof User
            && $viewer->can('update', $this->seller)
            && $this->seller->hasOpenAppeal();
    }
}
