<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderParty;
use Carbon\CarbonInterface;
use Database\Factories\OrderMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing one side of an order said to the other (ADR 0050).
 *
 * Nothing here is fillable. The body comes from a request, but it arrives
 * through an action that has already established who is writing, and the sender
 * is the platform's to decide rather than the client's - a message whose sender
 * came from a request body is a message a buyer could sign as the shop.
 *
 * `sender` is a party rather than an account, because the order already names
 * both. See the migration for why that is not a shortcut.
 *
 * @property-read Order $order
 * @property int $id
 * @property int $order_id
 * @property OrderParty $sender
 * @property string $body
 * @property CarbonInterface|null $read_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class OrderMessage extends Model
{
    /** @use HasFactory<OrderMessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sender' => OrderParty::class,
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Whether this was written by the side that is reading it.
     *
     * The one question the frontend needs answered to draw a conversation, and
     * it is the API's to answer: the browser is never told which account the
     * other party is, only which side of its own order each message came from.
     */
    public function wasSentBy(OrderParty $party): bool
    {
        return $this->sender === $party;
    }
}
