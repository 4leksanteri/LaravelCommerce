<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderParty;
use App\Models\Order;

/**
 * One side has read what the other said (ADR 0050).
 *
 * **Only the messages they did not write.** A message is never unread to its
 * author, so marking a conversation read is a statement about the other side's
 * messages and nothing else - which is also what stops a shop opening its own
 * order and clearing the badge the buyer is waiting on.
 *
 * Its own endpoint rather than a side effect of reading the conversation,
 * because `GET` reads and does not change anything (root `CLAUDE.md` section 9).
 * A page that fetched a thread would otherwise mark it read on every refresh,
 * including the refresh that redrew it after a failure.
 *
 * Idempotent: a second call has nothing left to update and says so by answering
 * zero.
 */
final class MarkOrderMessagesRead
{
    /** @return int how many were waiting, which is zero on a second call */
    public function handle(Order $order, OrderParty $reader): int
    {
        return $order->messages()
            ->where('sender', '!=', $reader)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
