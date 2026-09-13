<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\Carrier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a shop may say when it marks an order sent (ADR 0049).
 *
 * **Both optional, and the dependency runs one way.** A seller posting an
 * untracked letter gives neither; one using a carrier this marketplace can link
 * to gives both.
 *
 * A carrier without a number is refused, because the only thing it could
 * produce is a link to a search for nothing - and the database refuses it too,
 * which is where that rule actually holds.
 *
 * A number without a carrier is **allowed**, and shown as text. Somebody
 * shipping with a courier that is not on the list still has something the buyer
 * can quote down a telephone, and refusing it would push them towards picking a
 * carrier that is not really carrying it.
 */
final class ShipOrderRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'carrier' => ['nullable', Rule::enum(Carrier::class)],

            // Long enough for any of these carriers, and bounded so a field
            // somebody pasted a page into is refused rather than stored.
            //
            // Required with a carrier and not the other way round: a carrier
            // alone could only produce a link to a search for nothing, while a
            // number alone is what somebody shipping with an unlisted courier
            // has, and it is still worth quoting.
            'tracking_number' => ['nullable', 'string', 'max:64', 'required_with:carrier'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tracking_number.required_with' => 'Give the tracking number, or leave the carrier blank.',
        ];
    }

    public function carrier(): ?Carrier
    {
        $carrier = $this->string('carrier')->trim()->toString();

        return $carrier === '' ? null : Carrier::from($carrier);
    }

    /** Trimmed, because a number with a space on the end is not a number. */
    public function trackingNumber(): ?string
    {
        $number = $this->string('tracking_number')->trim()->toString();

        return $number === '' ? null : $number;
    }
}
