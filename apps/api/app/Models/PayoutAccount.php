<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayoutStatus;
use Database\Factories\PayoutAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop's Stripe connected account, and the last thing Stripe said about it.
 *
 * Nothing here is fillable, and nothing needs to be. Every column is either a
 * copy of Stripe's answer, written by `SyncPayoutAccount`, or the evidence of
 * Stripe's terms being accepted, written by the action that recorded it. None
 * of it is something a request body sets (ADR 0031).
 *
 * @property-read Seller $seller
 */
class PayoutAccount extends Model
{
    /** @use HasFactory<PayoutAccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payouts_enabled' => 'boolean',
            'requirements' => 'array',
            'requirements_due_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * Where the shop stands, read off what Stripe last said.
     *
     * In this order, and the order is the rule. A rejection outranks anything
     * still due, because nothing the seller sends will change it. Something due
     * outranks what is left, because it is the one state the seller can act
     * on. After that the account either works or Stripe is still looking.
     *
     * "Works" is two things: Stripe will accept transfers into the account, and
     * will pay them out to a bank. A shop with the first and not the second
     * could be sent money it cannot reach.
     */
    public function status(): PayoutStatus
    {
        if (str_starts_with((string) $this->disabled_reason, 'rejected.')) {
            return PayoutStatus::Rejected;
        }

        if ($this->outstandingRequirements() !== []) {
            return PayoutStatus::ActionRequired;
        }

        if ($this->transfers_status === 'active' && $this->payouts_enabled) {
            return PayoutStatus::Active;
        }

        return PayoutStatus::InReview;
    }

    /**
     * What Stripe needs now: `currently_due`, and `past_due`, which is what is
     * overdue and has already restricted the account.
     *
     * @return list<string>
     */
    public function outstandingRequirements(): array
    {
        return array_values(array_unique([
            ...$this->requirementList('currently_due'),
            ...$this->requirementList('past_due'),
        ]));
    }

    /**
     * What Stripe tried and could not verify, with its reason.
     *
     * @return list<array{requirement: string, code: string, reason: string}>
     */
    public function requirementErrors(): array
    {
        $errors = $this->requirements['errors'] ?? [];

        if (! is_array($errors)) {
            return [];
        }

        $readable = [];

        foreach ($errors as $error) {
            if (
                is_array($error)
                && is_string($error['requirement'] ?? null)
                && is_string($error['code'] ?? null)
                && is_string($error['reason'] ?? null)
            ) {
                $readable[] = [
                    'requirement' => $error['requirement'],
                    'code' => $error['code'],
                    'reason' => $error['reason'],
                ];
            }
        }

        return $readable;
    }

    /**
     * One of Stripe's lists of requirement paths, as strings and nothing else.
     *
     * The column is whatever Stripe sent, so it is read defensively rather
     * than trusted to have kept its shape.
     *
     * @return list<string>
     */
    private function requirementList(string $key): array
    {
        $values = $this->requirements[$key] ?? [];

        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
