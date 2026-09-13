<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrderMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message in one order's conversation (ADR 0050).
 *
 * **`sender` is the side, not the account**, and it is deliberately not
 * `sent_by_you`. The two audiences read this at two different addresses with
 * two different components, so which side is looking is a routing fact the
 * frontend already holds rather than a rule it would be re-deriving. It is the
 * same shape `cancelled_by` and `completed_by` are published in, and
 * `order-timeline` already turns those into "You cancelled it" on one side and
 * the shop's name on the other.
 *
 * The enum rather than its value, so the generator publishes a union of the
 * actual cases and a component switching on it is exhaustive.
 *
 * **No author name.** A buyer writes to a shop and a shop writes to a buyer;
 * both already know who they are talking to from the order, and putting the
 * shop owner's personal name on their messages would publish something the
 * shopfront does not.
 */
final class OrderMessageResource extends JsonResource
{
    public function __construct(private readonly OrderMessage $message)
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->message->id,
            'sender' => $this->message->sender,
            'body' => $this->message->body,
            'sent_at' => $this->message->created_at?->toIso8601String(),

            /*
             * When the other side read it, and null until they have.
             *
             * Annotated because a declared return type carries no null into the
             * contract, which ADR 0043 and ADR 0047 both had to learn - the
             * `/** @var *\/` annotation is the mechanism, not the return type.
             *
             * @var string|null
             */
            'read_at' => $this->message->read_at?->toIso8601String(),
        ];
    }
}
