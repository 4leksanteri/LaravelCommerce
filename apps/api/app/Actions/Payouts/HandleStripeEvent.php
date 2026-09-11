<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Models\PayoutAccount;
use Illuminate\Support\Facades\DB;
use Stripe\Event;

/**
 * Acts on a verified Stripe event, once.
 *
 * **Once** is the primary key of `stripe_events`. The event is recorded in the
 * same transaction as whatever it causes, so a failure part-way leaves no
 * record behind, Stripe is answered with an error, and it tries again. A
 * delivery that succeeded cannot be repeated by a retry arriving after it,
 * and two arriving together are serialised by the index.
 *
 * Only `account.updated` is acted on so far. It is load-bearing rather than a
 * nicety: it is how this application learns that Stripe has finished checking
 * somebody, or that a verified seller has been asked for something new
 * (ADR 0015).
 */
final class HandleStripeEvent
{
    private const array ACTED_ON = ['account.updated'];

    public function __construct(private readonly SyncPayoutAccount $sync) {}

    public function handle(Event $event): void
    {
        if (! in_array($event->type, self::ACTED_ON, true)) {
            return;
        }

        DB::transaction(function () use ($event): void {
            $firstDelivery = DB::table('stripe_events')->insertOrIgnore([
                'id' => $event->id,
                'type' => $event->type,
                'processed_at' => now(),
            ]) === 1;

            if (! $firstDelivery) {
                return;
            }

            $this->accountUpdated($event);
        });
    }

    private function accountUpdated(Event $event): void
    {
        $object = $event->data->object->toArray();
        $stripeAccountId = is_string($object['id'] ?? null) ? $object['id'] : null;

        $account = $stripeAccountId === null
            ? null
            : PayoutAccount::query()->where('stripe_account_id', $stripeAccountId)->first();

        // An account this platform controls that no shop points at - one made
        // by hand in Stripe's dashboard, say. There is nothing here to update.
        if (! $account instanceof PayoutAccount) {
            return;
        }

        // Fetched again rather than read from the event, which may be older
        // than what is already stored. SyncPayoutAccount says why.
        $this->sync->handle($account);
    }
}
