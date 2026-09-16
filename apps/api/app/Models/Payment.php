<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PaymentStatus;
use Carbon\CarbonInterface;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What was charged for one order, as Stripe last described it.
 *
 * A copy rather than a second opinion, exactly as `payout_accounts` is
 * (ADR 0031): Stripe owns whether money moved, and this row is what it last
 * said so that a page can be drawn without asking again. When the two
 * disagree, Stripe is right and a webhook brings this into line.
 *
 * **There is nothing fillable here.** Every column is platform-owned - an
 * intent id, a status, an amount read from the order - and no request body may
 * set one. The actions write through `forceFill`, which is the distinction
 * `apps/api/CLAUDE.md` draws between trusted code and a request.
 *
 * @property-read Order $order
 * @property int $id
 * @property int $order_id
 * @property string $stripe_payment_intent_id
 * @property string|null $stripe_client_secret
 * @property PaymentStatus $status
 * @property int $amount_minor
 * @property Currency $currency
 * @property string|null $stripe_payment_method_id
 * @property string|null $failure_reason
 * @property CarbonInterface|null $paid_at
 * @property int|null $platform_fee_minor
 * @property string|null $stripe_transfer_id
 * @property CarbonInterface|null $transferred_at
 * @property string|null $stripe_transfer_reversal_id
 * @property CarbonInterface|null $reversed_at
 * @property string|null $stripe_refund_id
 * @property CarbonInterface|null $refunded_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'currency' => Currency::class,
            'amount_minor' => 'integer',
            'platform_fee_minor' => 'integer',
            'paid_at' => 'datetime',
            'transferred_at' => 'datetime',
            'reversed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Whether the money is on the platform and the shop can get on with it. */
    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    /** Whether the shop has been sent its share. */
    public function isTransferred(): bool
    {
        return $this->transferred_at !== null;
    }

    /** Whether the buyer has had it back. */
    public function isRefunded(): bool
    {
        return $this->refunded_at !== null;
    }

    /**
     * Whether money that reached the shop has been pulled back (ADR 0061).
     *
     * `transferred_at` stays set beside this. The transfer happened and the fee
     * was taken, and both are facts about it - a reversal is a second event
     * recorded next to the first, never an undo of it.
     */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /**
     * Money that is on the platform and has gone neither way.
     *
     * The escrow position for one order, and the only question this row is
     * really asked: paid, and not yet sent anywhere.
     *
     * **Reversing does not make a payment held again**, and that is deliberate.
     * The money is back on the platform, but it is owed to the buyer rather
     * than waiting on the outcome of anything - and this answer gates the
     * dispute window (ADR 0051) and what stops an account closing (ADR 0058),
     * neither of which should reopen because a decision is midway through
     * being carried out.
     */
    public function isHeld(): bool
    {
        return $this->isPaid() && ! $this->isTransferred() && ! $this->isRefunded();
    }

    /**
     * Whether there is money at the shop to pull back (ADR 0061).
     *
     * Transferred, not already reversed, and not somehow refunded as well.
     * Stripe refuses a second reversal of the same transfer, and the
     * idempotency key means a retry is the same request rather than a second
     * helping - this is what keeps the question from being asked twice at all.
     */
    public function canBeReversed(): bool
    {
        return $this->isTransferred() && ! $this->isReversed() && ! $this->isRefunded();
    }

    /**
     * Whether the buyer can be given their money back.
     *
     * **Wider than `isHeld()`, and that is the whole of what ADR 0061 changed.**
     * It used to be the same question: money that had reached a shop could not
     * come back, so held and refundable meant one thing. A reversal puts the
     * money on the platform again while `transferred_at` stays set, so a
     * payment can be refundable and not held - which is exactly the state a
     * post-completion dispute passes through.
     *
     * There is still one refund path. ADR 0041 said there is no second way to
     * pay anybody, and widening this gate is what keeps that true rather than
     * adding a parallel action for the reversed case.
     */
    public function canBeRefunded(): bool
    {
        return $this->isPaid()
            && ! $this->isRefunded()
            && (! $this->isTransferred() || $this->isReversed());
    }

    /**
     * Whether this money could still be returned to the buyer (ADR 0061).
     *
     * Wider than `canBeRefunded()` by the step in between: a transfer that has
     * not been reversed yet is money that *can* come back, it just has to be
     * pulled off the connected account first. So this is the question a dispute
     * window asks - is there anything a decision could still move - while
     * `canBeRefunded()` is the narrower question the refund itself asks.
     *
     * Paid and not already refunded is the whole of it. Where the money is
     * sitting decides how many steps it takes, not whether it is possible.
     */
    public function canComeBack(): bool
    {
        return $this->isPaid() && ! $this->isRefunded();
    }

    /**
     * What the marketplace keeps from this payment, in minor units.
     *
     * **The recorded figure wins once there is one.** What was kept is a fact
     * about a transfer that happened, not something to re-derive later from a
     * rate that has changed since (ADR 0041).
     *
     * Before the transfer there is nothing recorded, so this is what the
     * current rate would take - which is the question a shop looking at an
     * order it has not been paid for yet is asking. `TransferToShop` computes
     * the real fee through here as well, so the figure a seller was shown and
     * the figure Stripe is sent cannot come from two different sums.
     *
     * Integer arithmetic throughout: multiplied before dividing, so nothing is
     * ever a float, and the remainder is dropped rather than rounded. The drop
     * favours the shop, which is the right direction for a fraction of a cent
     * nobody can pay anyway (ADR 0004).
     */
    public function platformFeeMinor(): int
    {
        if ($this->platform_fee_minor !== null) {
            return $this->platform_fee_minor;
        }

        $bps = (int) config('payments.platform_fee_bps');

        if ($bps <= 0) {
            return 0;
        }

        return intdiv($this->goodsMinor() * $bps, 10_000);
    }

    /**
     * What was charged for the things, without the postage (ADR 0057).
     *
     * **The fee is taken on the goods and not on the carriage.** Postage is a
     * cost the shop actually pays a carrier, passed through to the buyer; a
     * marketplace keeping five per cent of it would be charging a shop for the
     * privilege of posting a parcel. Whichever way that went it had to be
     * decided, because `total_minor` includes shipping and the naive sum would
     * have taken a cut of it silently.
     *
     * Reached through the order, which `Order::payment()` chaperones - so on
     * every path that exists today (an order's own resource, `TransferToShop`,
     * `SettleOutstandingPayments`) the order is already in memory and this
     * costs no query. A payment loaded on its own would fetch it, which is
     * slower and still correct.
     */
    public function goodsMinor(): int
    {
        return $this->amount_minor - $this->order->shipping_minor;
    }

    /** What the shop gets: what was charged, less what the marketplace keeps. */
    public function shopReceivesMinor(): int
    {
        return $this->amount_minor - $this->platformFeeMinor();
    }
}
