<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DisputeResolution;
use Carbon\CarbonInterface;
use Database\Factories\DisputeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer says their order did not arrive, or did not arrive as described
 * (ADR 0051).
 *
 * Nothing here is fillable. The reason comes from a request, but it arrives
 * through an action that has already established the order is the caller's and
 * that the money is still held; everything about the decision is the platform's
 * to set.
 *
 * **An open dispute is what stops the clock.** `AutoCompleteShippedOrders`
 * skips orders that have one, so the deadline cannot quietly release the money
 * for the thing being argued about. The deadline itself is left alone rather
 * than cleared, because `orders_auto_complete_at_check` requires a shipped
 * order to have one - and because both parties should still be able to see the
 * date it would have completed on.
 *
 * @property-read Order $order
 * @property-read User|null $resolvedBy
 * @property int $id
 * @property int $order_id
 * @property string $reason
 * @property DisputeResolution|null $resolution
 * @property string|null $resolution_note
 * @property CarbonInterface|null $resolved_at
 * @property int|null $resolved_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Dispute extends Model
{
    /** @use HasFactory<DisputeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolution' => DisputeResolution::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The member of staff who decided it.
     *
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Whether it is still waiting on the platform.
     *
     * Read from `resolved_at` rather than from a status column, for the reason
     * `PayoutAccount` derives its status: a second column saying what four
     * others already say is a column that gets to disagree with them.
     */
    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
