<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\Report;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One report, as the platform's queue shows it (ADR 0054).
 *
 * **Staff are deciding about something they cannot otherwise see**, so the
 * subject is summarised here rather than left as a type and an id: what it is,
 * what it says, and where to look at it properly. That is the same reasoning
 * `StaffDisputeResource` gives for carrying the order around a dispute.
 *
 * **The subject can be gone**, because a morph carries no foreign key and a
 * seller may delete a flagged listing. `subject` is null then, and the queue
 * says so - which is itself worth knowing, since a shop deleting what it was
 * reported for is a fact about the shop.
 *
 * Who reported it is **not** published. Moderation that names its reporter is
 * moderation nobody uses twice, and the decision is the platform's rather than
 * the reporter's.
 */
final class ReportResource extends JsonResource
{
    public function __construct(private readonly Report $report)
    {
        parent::__construct($report);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->report->id,
            'reason' => $this->report->reason,
            /** @var string|null */
            'note' => $this->report->note,

            'is_open' => $this->report->isOpen(),
            'reported_at' => $this->report->created_at?->toIso8601String(),

            /*
             * Null while it is open. Annotated because a declared return type
             * carries no null into the contract, which ADR 0043 and ADR 0047
             * both learned the hard way.
             *
             * @var bool|null
             */
            'upheld' => $this->report->upheld,
            /** @var string|null */
            'outcome_note' => $this->report->outcome_note,
            /** @var string|null */
            'decided_at' => $this->report->reviewed_at?->toIso8601String(),

            /*
             * What was reported, or null if it has gone since.
             *
             * @var array{kind: string, title: string, body: string|null, href: string|null}|null
             */
            'subject' => $this->subject(),
        ];
    }

    /**
     * The reported thing, in the few words a decision needs.
     *
     * `href` is the address on the web application rather than on this API, for
     * the reason every notification builds one: the queue is a page, and a
     * moderator following a link wants what a shopper would see.
     *
     * @return array{kind: string, title: string, body: string|null, href: string|null}|null
     */
    private function subject(): ?array
    {
        $subject = $this->report->reportable;

        if ($subject instanceof Product) {
            return [
                'kind' => 'listing',
                'title' => $subject->name,
                'body' => $subject->description,
                'href' => "/shops/{$subject->seller->slug}/products/{$subject->slug}",
            ];
        }

        if ($subject instanceof Review) {
            $listing = $subject->product;

            return [
                'kind' => 'review',

                // What it is about, because a review's own words are the body
                // and "4 out of 5" alone would not say what was reviewed.
                'title' => "{$subject->rating} out of 5 on {$listing->name}",
                'body' => $subject->body,
                'href' => "/shops/{$listing->seller->slug}/products/{$listing->slug}",
            ];
        }

        return null;
    }
}
