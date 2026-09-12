<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Paying for a checkout with a card the browser already turned into a payment
 * method.
 *
 * **The only thing sent is a handle.** Stripe.js collects the card inside its
 * own frame and returns `pm_...`; no card number, expiry or security code ever
 * reaches this application, which is what keeps it out of PCI scope (ADR 0040).
 *
 * Nothing here is a figure. What each order costs was snapshotted at checkout
 * and the intents were created from those totals - a request body cannot change
 * what somebody is charged, which is the claim ADR 0011 makes about checkout
 * and this keeps.
 */
final class PayCheckoutRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * The prefix is checked because it is free to check and it catches
             * the one mistake a client can make here: sending a payment intent
             * id, or a token, where a payment method belongs. Whether it exists
             * is Stripe's to say.
             */
            'payment_method' => ['required', 'string', 'starts_with:pm_', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Enter a card to pay with.',
            'payment_method.starts_with' => 'That is not a payment method Stripe issued.',
        ];
    }
}
