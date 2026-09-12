<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Everything the browser needs to pay for one basket.
 *
 * A basket spanning three shops is three orders in three currencies and so
 * three payments (ADR 0015), and this is all of them at once: the buyer
 * entered one card, and what they want to know is whether the basket is paid
 * for, not which of three intents is outstanding.
 *
 * **The publishable key is sent from here** rather than built into the web
 * application. It is publishable by definition, and sending it with the thing
 * that needs it keeps every Stripe setting in one place (ADR 0040).
 *
 * `is_paid` is the answer, not the inputs: a page draws "paid" from it rather
 * than deciding for itself what a list of five statuses adds up to (root
 * CLAUDE.md section 4).
 */
final class CheckoutPaymentResource extends JsonResource
{
    /**
     * @param  Collection<int, Order>  $orders  every order in one checkout,
     *                                          with its payment loaded
     */
    public function __construct(
        private readonly string $checkoutReference,
        private readonly Collection $orders,
    ) {
        parent::__construct($orders);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payments = $this->payments();

        return [
            'checkout_reference' => $this->checkoutReference,

            // Null when no key is configured, which is a stack that cannot take
            // a payment at all. The page says so rather than mounting a card
            // form that could never work.
            'publishable_key' => $this->publishableKey(),

            'is_paid' => $this->isPaid($payments),

            /** @var list<PaymentResource> */
            'payments' => $this->resources(),
        ];
    }

    /**
     * One resource per order that has a payment, carrying the order's own
     * reference: the buyer is looking at a basket, and "which order is this"
     * is the only way to tell three of them apart.
     *
     * @return list<PaymentResource>
     */
    private function resources(): array
    {
        $resources = [];

        foreach ($this->orders as $order) {
            $payment = $order->payment;

            if ($payment instanceof Payment) {
                $resources[] = new PaymentResource($payment, $order->reference);
            }
        }

        return $resources;
    }

    /**
     * @return Collection<int, Payment>
     */
    private function payments(): Collection
    {
        $payments = [];

        foreach ($this->orders as $order) {
            $payment = $order->payment;

            if ($payment instanceof Payment) {
                $payments[] = $payment;
            }
        }

        return new Collection($payments);
    }

    /**
     * Every order in the basket is paid for.
     *
     * An order without a payment at all counts as unpaid, which is the honest
     * reading: its intent has not been created yet.
     *
     * @param  Collection<int, Payment>  $payments
     */
    private function isPaid(Collection $payments): bool
    {
        if ($payments->count() !== $this->orders->count() || $payments->isEmpty()) {
            return false;
        }

        return $payments->every(static fn (Payment $payment): bool => $payment->isPaid());
    }

    private function publishableKey(): ?string
    {
        $key = config('services.stripe.publishable_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
