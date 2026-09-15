<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Appeal;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One appeal, as the platform's queue shows it and as its appellant reads it
 * back (ADR 0059).
 *
 * **The subject is summarised rather than left as a type and an id**, because
 * staff are deciding about something they cannot otherwise see - the same
 * reasoning `ReportResource` and `StaffDisputeResource` both give. It carries
 * what was stopped, and *why the platform stopped it*, because an appeal is an
 * argument against that reason and reading one without it is reading half a
 * case.
 *
 * **The subject can be gone.** A morph has no foreign key and a seller may
 * delete the listing they were appealing about, so `subject` is null then and
 * the queue says so.
 *
 * Who decided is not published, for the reason a dispute's `resolved_by` and a
 * suspension's `suspended_by` are not: the decision is the platform's rather
 * than an individual's, and naming somebody invites the argument to follow them.
 */
final class AppealResource extends JsonResource
{
    public function __construct(private readonly Appeal $appeal)
    {
        parent::__construct($appeal);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->appeal->id,
            'reason' => $this->appeal->reason,

            'is_open' => $this->appeal->isOpen(),
            'raised_at' => $this->appeal->created_at?->toIso8601String(),

            /*
             * Null while it is open. Annotated because a declared return type
             * carries no null into the contract, which ADR 0043 and ADR 0047
             * both learned the hard way.
             *
             * @var bool|null
             */
            'upheld' => $this->appeal->upheld,
            /** @var string|null */
            'outcome_note' => $this->appeal->outcome_note,
            /** @var string|null */
            'decided_at' => $this->appeal->reviewed_at?->toIso8601String(),

            /*
             * What was stopped, why, and where to look at it.
             *
             * @var array{kind: string, title: string, sanction_reason: string|null, href: string|null}|null
             */
            'subject' => $this->subject(),
        ];
    }

    /**
     * The thing that was stopped, in the few words a decision needs.
     *
     * `sanction_reason` is the platform's own words when it stopped the thing -
     * the suspension reason, the removal reason, the reason a review was
     * hidden. An appeal argues against exactly that sentence, so a queue that
     * omitted it would be showing the answer without the question.
     *
     * @return array{kind: string, title: string, sanction_reason: string|null, href: string|null}|null
     */
    private function subject(): ?array
    {
        $subject = $this->appeal->appealable;

        if ($subject instanceof Seller) {
            return [
                'kind' => 'shop',
                'title' => $subject->shop_name,
                'sanction_reason' => $subject->suspension_reason,
                'href' => "/shops/{$subject->slug}",
            ];
        }

        if ($subject instanceof Product) {
            return [
                'kind' => 'listing',
                'title' => $subject->name,
                'sanction_reason' => $subject->removal_reason,

                // A removed listing is not public, so there is nowhere a
                // shopper could follow this to. The shop's page is the nearest
                // real address, and it is where the thing lived.
                'href' => "/shops/{$subject->seller->slug}",
            ];
        }

        if ($subject instanceof Review) {
            $listing = $subject->product;

            return [
                'kind' => 'review',
                'title' => "{$subject->rating} out of 5 on {$listing->name}",
                'sanction_reason' => $subject->hidden_reason,
                'href' => "/shops/{$listing->seller->slug}/products/{$listing->slug}",
            ];
        }

        return null;
    }
}
