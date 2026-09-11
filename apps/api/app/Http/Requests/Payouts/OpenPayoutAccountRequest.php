<?php

declare(strict_types=1);

namespace App\Http\Requests\Payouts;

use App\Enums\PayoutCountry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OpenPayoutAccountRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Where the seller lives, from PayoutCountry's list. Asked once:
            // Stripe does not move an account from one country to another.
            'country' => ['required', Rule::enum(PayoutCountry::class)],

            // Stripe's Connected Account Agreement. The account is opened with
            // the acceptance on it, so there is no account without one.
            'accept_terms' => ['required', 'accepted'],
        ];
    }

    public function country(): PayoutCountry
    {
        return PayoutCountry::from($this->string('country')->toString());
    }
}
