<?php

declare(strict_types=1);

namespace App\Notifications\Orders;

use App\Enums\OrderParty;
use App\Models\Order;
use App\Models\OrderMessage;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To whichever side did not write it: the other one has said something
 * (ADR 0050).
 *
 * **The same order has two addresses**, and this picks the recipient's. A shop
 * reads it at `/seller/orders/...` and a buyer at `/account/orders/...`, so a
 * single link would send half of everybody to a page that answers 404.
 *
 * The message is quoted in full rather than announced. "You have a new message"
 * with a link is a second trip for something that fits in a sentence, and a
 * seller reading mail on a phone can often answer without opening anything.
 */
final class MessageReceived extends QueuedNotification
{
    public function __construct(public Order $order, public OrderMessage $message)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;
        $fromBuyer = $this->message->sender === OrderParty::Buyer;

        // Who wrote it, in the words the recipient knows them by: a shop has a
        // name of its own, and a buyer is a person.
        $author = $fromBuyer ? $order->user->name : $order->seller->shop_name;

        $page = $this->frontend($fromBuyer
            ? "/seller/orders/{$order->reference}"
            : "/account/orders/{$order->reference}");

        return (new MailMessage)
            ->subject("{$author} sent you a message about order {$order->reference}")
            ->line($this->quoted($author).' wrote about order '.$order->reference.':')

            // Somebody else's words, so they go through `quoted()` - a message
            // containing Markdown link syntax would otherwise arrive as a link
            // in a stranger's inbox.
            ->line('"'.$this->quoted($this->message->body).'"')
            ->line('You can reply from the order page.')
            ->action('Read and reply', $page);
    }
}
