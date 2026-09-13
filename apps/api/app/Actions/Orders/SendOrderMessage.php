<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderParty;
use App\Models\Order;
use App\Models\OrderMessage;
use App\Notifications\Orders\MessageReceived;

/**
 * One side of an order says something to the other (ADR 0050).
 *
 * **There is no state to be in.** Every other action here refuses out of turn -
 * an order cannot be accepted twice, or shipped before it is accepted - and
 * this one deliberately does not. A conversation is most needed exactly where
 * the lifecycle has stopped helping: a parcel that never came, a cancellation
 * whose reason needs explaining, a return being arranged after completion.
 * Closing the conversation when the order ends would shut it at the moment it
 * matters.
 *
 * **Who is writing is the platform's answer, never the payload's.** The sender
 * comes from which route was called, each of which is already scoped to one
 * side's own relation - so a buyer cannot sign a message as the shop by sending
 * a field, because there is no field to send.
 *
 * The other side is told by mail, queued and only after the commit, like every
 * other notification here (ADR 0035).
 */
final class SendOrderMessage
{
    public function handle(Order $order, OrderParty $sender, string $body): OrderMessage
    {
        $message = new OrderMessage;

        $message->forceFill([
            'order_id' => $order->id,
            'sender' => $sender,
            'body' => $body,
        ]);

        $message->save();

        $this->tellTheOtherSide($order, $message, $sender);

        return $message;
    }

    /**
     * Mail to whoever did not write it.
     *
     * A message nobody is told about is a message nobody answers, and neither
     * party is sitting on this page waiting. Which address it links to depends
     * on which side is being written to: the same order has two of them.
     */
    private function tellTheOtherSide(Order $order, OrderMessage $message, OrderParty $sender): void
    {
        $recipient = $sender === OrderParty::Buyer
            ? $order->seller->user
            : $order->user;

        $recipient->notify(new MessageReceived($order, $message));
    }
}
