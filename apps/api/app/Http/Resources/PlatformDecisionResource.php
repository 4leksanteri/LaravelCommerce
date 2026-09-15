<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Dispute;
use App\Models\PlatformDecision;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One decision on a shop's record (ADR 0060).
 *
 * **The subject is summarised rather than left as a type and an id**, the same
 * reasoning `ReportResource` and `AppealResource` both give: staff are reading
 * about something they cannot otherwise see, and a record of type names and
 * numbers is a record nobody reads.
 *
 * **The subject can be gone.** A morph carries no foreign key and a seller may
 * delete the listing they were punished over, so `subject` is null then and the
 * page says so - a shop deleting what it was punished for is itself worth
 * seeing.
 *
 * **Who decided is not published**, for the reason a dispute's `resolved_by`, a
 * suspension's `suspended_by` and an appeal's `reviewed_by` are not: the
 * decision is the platform's rather than an individual's, and naming somebody
 * invites the argument to follow them. It is recorded, and stays in the
 * database.
 *
 * `kind` is the enum rather than its value, so the contract carries the union
 * of actual cases and the frontend can switch on it. The wording it is drawn
 * with lives in the frontend, exactly as `ReportReason`'s does - that is
 * presentation, not a rule. `counts_against_the_shop` is not presentation: it
 * is the platform's own judgement about its own decision, so the API answers
 * it.
 */
final class PlatformDecisionResource extends JsonResource
{
    public function __construct(private readonly PlatformDecision $decision)
    {
        parent::__construct($decision);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->decision->id,

            'kind' => $this->decision->kind,

            /*
             * Whether this one is a mark against the shop. Half of these are
             * the platform deciding in its favour - a reinstatement, a restored
             * listing, a dispute released to it - and a record that counted
             * those would answer "how many times has this shop been in
             * trouble" with the times it was cleared.
             *
             * @var bool
             */
            'counts_against_the_shop' => $this->decision->kind->countsAgainstTheShop(),

            /*
             * The words given at the time, where there were any. Lifting a
             * suspension is not asked for one.
             *
             * @var string|null
             */
            'reason' => $this->decision->reason,

            /** @var string|null */
            'decided_at' => $this->decision->created_at?->toIso8601String(),

            /*
             * What it was about, in the few words a record needs.
             *
             * @var array{kind: string, title: string, href: string|null}|null
             */
            'subject' => $this->subject(),
        ];
    }

    /**
     * What the decision concerned.
     *
     * Three kinds, and no more: a shop, one of its listings, or a dispute on
     * one of its orders. An appeal is recorded against the thing it argued
     * about rather than against itself, which is what keeps this from having to
     * resolve a morph through a morph.
     *
     * `href` is only ever the shop's public page, and null for a dispute. A
     * removed listing is not public and staff have no page for one order, so
     * every other link this could offer would be a link to a 404.
     *
     * @return array{kind: string, title: string, href: string|null}|null
     */
    private function subject(): ?array
    {
        $subject = $this->decision->subject;

        if ($subject instanceof Seller) {
            return [
                'kind' => 'shop',
                'title' => $subject->shop_name,
                'href' => "/shops/{$subject->slug}",
            ];
        }

        if ($subject instanceof Product) {
            return [
                'kind' => 'listing',
                'title' => $subject->name,
                'href' => "/shops/{$subject->seller->slug}",
            ];
        }

        if ($subject instanceof Dispute) {
            return [
                'kind' => 'dispute',
                'title' => "Order {$subject->order->reference}",
                'href' => null,
            ];
        }

        return null;
    }
}
