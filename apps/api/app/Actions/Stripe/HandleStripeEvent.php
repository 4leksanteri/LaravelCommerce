<?php

declare(strict_types=1);

namespace App\Actions\Stripe;

use App\Actions\Payments\SyncPayment;
use App\Actions\Payouts\SyncPayoutAccount;
use App\Models\Payment;
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
 * **Nothing here trusts the event's copy of the object.** Stripe does not
 * promise to deliver events in order, so one that arrives late carries
 * something older than what is already stored. Every case takes the id out of
 * the event and asks Stripe for the object as it is now.
 *
 * This used to live under `Actions\Payouts`, when a connected account was the
 * only thing Stripe had to say anything about. Payments arrive through the same
 * signature check and the same idempotency, so it moved up to where both can
 * reach it and each domain keeps its own syncing (ADR 0040).
 */
final class HandleStripeEvent
{
    private const array ACTED_ON = [
        // How this application learns that Stripe has finished checking
        // somebody, or that a verified seller has been asked for something new.
        'account.updated',

        // Whether the money arrived. These are the truth about a payment, and
        // the confirmation the buyer's browser reports is only a hint: a
        // browser that closes mid-confirmation still produces these.
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
    ];

    public function __construct(
        private readonly SyncPayoutAccount $syncAccount,
        private readonly SyncPayment $syncPayment,
    ) {}

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

            match ($event->type) {
                'account.updated' => $this->accountUpdated($event),
                default => $this->paymentChanged($event),
            };
        });
    }

    private function accountUpdated(Event $event): void
    {
        $stripeAccountId = $this->objectId($event);

        $account = $stripeAccountId === null
            ? null
            : PayoutAccount::query()->where('stripe_account_id', $stripeAccountId)->first();

        // An account this platform controls that no shop points at - one made
        // by hand in Stripe's dashboard, say. There is nothing here to update.
        if (! $account instanceof PayoutAccount) {
            return;
        }

        $this->syncAccount->handle($account);
    }

    /**
     * A payment moved. Which way is not read from the event: the intent is
     * fetched and the row is brought into line with it, so the three events
     * this acts on need no separate handling and an out-of-order delivery
     * cannot move a payment backwards.
     */
    private function paymentChanged(Event $event): void
    {
        $intentId = $this->objectId($event);

        $payment = $intentId === null
            ? null
            : Payment::query()->where('stripe_payment_intent_id', $intentId)->first();

        // An intent this platform created for something other than an order,
        // or one whose row was never written. Nothing to bring into line.
        if (! $payment instanceof Payment) {
            return;
        }

        $this->syncPayment->handle($payment);
    }

    private function objectId(Event $event): ?string
    {
        $object = $event->data->object->toArray();

        return is_string($object['id'] ?? null) ? $object['id'] : null;
    }
}
