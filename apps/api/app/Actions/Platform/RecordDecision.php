<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Enums\DecisionKind;
use App\Models\PlatformDecision;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes down what the platform decided about a shop (ADR 0060).
 *
 * **Called from inside the transaction that takes the decision**, every time.
 * That is the same reasoning `DecideReport` gives for taking a listing down
 * beside the report that ordered it: a decision that happened without its
 * record, or a record with no decision behind it, is the one inconsistency
 * nobody could explain afterwards.
 *
 * It is deliberately small and deliberately not a model method. Where the row
 * comes from matters - six actions write one - and a `PlatformDecision::record`
 * on the model would put a workflow in a place root CLAUDE.md section 6 keeps
 * workflows out of.
 */
final class RecordDecision
{
    /**
     * @param  Seller  $seller  the shop this counts against
     * @param  Model  $subject  what it was about: the shop, a listing, a dispute, an appeal
     * @param  string|null  $reason  the words given at the time, where there were any
     * @param  User|null  $staff  who decided, recorded and never published
     */
    public function handle(
        DecisionKind $kind,
        Seller $seller,
        Model $subject,
        ?string $reason = null,
        ?User $staff = null,
    ): PlatformDecision {
        $decision = new PlatformDecision;

        // Nothing here is fillable: every value has been established by the
        // action that is taking the decision, not sent by a client.
        $decision->forceFill([
            'seller_id' => $seller->getKey(),
            'kind' => $kind,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'reason' => $reason,
            'decided_by' => $staff?->getKey(),
            'created_at' => now(),
        ])->save();

        $decision->setRelation('seller', $seller);
        $decision->setRelation('subject', $subject);

        return $decision;
    }
}
