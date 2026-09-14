<?php

declare(strict_types=1);

namespace App\Http\Requests\Moderation;

use App\Enums\ReportReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What somebody says when they report a listing or a review (ADR 0054).
 *
 * **The reason is required and the note is not.** The reason is what sorts a
 * queue - "counterfeit" and "abusive" want different kinds of attention - and
 * for most reports it is the whole of it. `Other` is the case that needs words,
 * and the rule below says so rather than leaving a queue full of "something
 * else" with nothing after it.
 *
 * Whether this person may report this thing at all is not here: what they can
 * see is settled by the storefront's own scopes long before a payload is read.
 */
final class ReportContentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(ReportReason::class)],

            // Required only when the reason carries no meaning on its own.
            // "Something else" with nothing after it is a report nobody can act
            // on, which is the same argument `RejectSellerRequest` makes for a
            // floor on a rejection.
            'note' => [
                Rule::requiredIf($this->input('reason') === ReportReason::Other->value),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function reason(): ReportReason
    {
        return ReportReason::from((string) $this->input('reason'));
    }

    /**
     * The reporter's own words, or null when the reason said it all.
     *
     * Trimmed, and an empty string becomes null rather than a blank note: the
     * table refuses one, and a space bar is not a report.
     */
    public function note(): ?string
    {
        $note = trim((string) $this->input('note', ''));

        return $note === '' ? null : $note;
    }
}
