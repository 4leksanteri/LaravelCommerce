<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\DisputeResolution;
use App\Models\Dispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A dispute, as the two parties to the order see it (ADR 0051).
 *
 * **Both sides see the same thing**, including the buyer's reason and the
 * platform's note. A decision one party can read and the other cannot is how a
 * marketplace ends up arguing with itself over what was said.
 *
 * No order context here. This is published nested on an order that already says
 * what it is, who it is from and what it cost - repeating any of that would be
 * a second copy on the same page. The staff queue, which has no order around
 * it, uses `StaffDisputeResource` instead.
 *
 * Who decided it is not published. That is the platform's answer, not a
 * person's, and naming an individual member of staff on a decision about
 * somebody's money invites the complaint to follow them personally.
 */
final class DisputeResource extends JsonResource
{
    public function __construct(private readonly Dispute $dispute)
    {
        parent::__construct($dispute);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->dispute->id,
            'reason' => $this->dispute->reason,

            // Open until the platform decides. Derived rather than stored, so
            // it cannot disagree with the dates beside it.
            'is_open' => $this->dispute->isOpen(),

            /*
             * Null while it is open, and the annotation is what carries that
             * into the contract - a declared return type does not, which
             * ADR 0043 and ADR 0047 both learned the hard way.
             *
             * @var DisputeResolution|null
             */
            'resolution' => $this->dispute->resolution,
            /** @var string|null */
            'resolution_note' => $this->dispute->resolution_note,

            'opened_at' => $this->dispute->created_at?->toIso8601String(),
            /** @var string|null */
            'resolved_at' => $this->dispute->resolved_at?->toIso8601String(),
        ];
    }
}
