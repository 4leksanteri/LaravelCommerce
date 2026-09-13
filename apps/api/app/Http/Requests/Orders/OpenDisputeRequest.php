<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What a buyer says when they open a dispute.
 *
 * Required, and the only field. A dispute with no reason is one nobody can
 * decide and the shop cannot answer - which is why the column is `not null` as
 * well.
 *
 * Whether this order may be disputed at all is deliberately not here. That is a
 * question about the order's status and where its money is, not about the
 * payload, and a form request that went looking would be doing the action's job
 * (`apps/api/CLAUDE.md` section 7).
 */
final class OpenDisputeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Room to describe what arrived and what was expected, without
            // becoming a document nobody reads.
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * Trimmed. `TrimStrings` has already been through the input, so whitespace
     * alone has failed `required` before reaching here; this is the second half
     * of the promise `disputes_reason_not_blank` makes.
     */
    public function reason(): string
    {
        return trim((string) $this->input('reason', ''));
    }
}
