<?php

declare(strict_types=1);

namespace App\Http\Requests\Payouts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An identity document, on its way to Stripe.
 *
 * Stripe's own limits for one: a JPEG, a PNG or a PDF, of at most 10 MB. They
 * are checked here so that a file Stripe would refuse is refused before it is
 * sent anywhere. `mimes` reads the file's contents rather than trusting the
 * name or the type the browser claimed, which is the rule for every upload
 * (root CLAUDE.md section 11).
 */
final class StoreIdentityDocumentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'front' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],

            // A passport has no back. Stripe says when one was needed.
            'back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }
}
