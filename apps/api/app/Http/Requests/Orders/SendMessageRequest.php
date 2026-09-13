<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What a message may say.
 *
 * One field, and it is required - unlike a review's words, which are optional
 * because a rating on its own says something. A message with no body says
 * nothing at all.
 *
 * **Who is sending it is not here, and must not be.** The sender is decided by
 * which route was called, each scoped to one side of the order (ADR 0050). A
 * `sender` field in a payload would be a buyer's opportunity to write as the
 * shop.
 *
 * Whether these two are still talking is not here either. There is no state in
 * which a message is refused, which is the decision `SendOrderMessage` explains.
 */
final class SendMessageRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Long enough to explain what went wrong with a parcel, short
            // enough that a conversation is still readable. The column takes
            // more; a request does not need to.
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * The words, trimmed.
     *
     * Laravel's `TrimStrings` has already been through the input, so whitespace
     * on its own has failed `required` before reaching here. This is the second
     * half of the same promise the database makes with
     * `order_messages_body_not_blank`.
     */
    public function body(): string
    {
        return trim((string) $this->input('body', ''));
    }
}
