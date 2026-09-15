<?php

declare(strict_types=1);

namespace App\Http\Requests\Appeals;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What somebody says when they answer back (ADR 0059).
 *
 * **The reason is required, with a floor**, unlike a report's optional note. A
 * report's reason is often the whole of it - "this is counterfeit" needs no
 * essay - but an appeal is an argument, and one with nothing in it is nothing
 * for a person to weigh. The same floor a rejection and a suspension have, and
 * for the same reason: somebody has to act on it.
 *
 * Whether this person may appeal this thing is not here: each endpoint resolves
 * its subject through the caller's own shop, listing or review, long before a
 * payload is read.
 */
final class RaiseAppealRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason', ''));
    }
}
