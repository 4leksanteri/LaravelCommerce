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
     * Money that is on the platform and has gone neither way.
     *
     * The escrow position for one order, and the only question this row is
     * really asked: paid, and not yet sent anywhere.
     */
    public function isHeld(): bool
    {
        return $this->isPaid() && ! $this->isTransferred() && ! $this->isRefunded();
    }
}
