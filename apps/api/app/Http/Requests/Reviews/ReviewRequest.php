<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What a review may say, leaving one or changing it.
 *
 * **One class for both endpoints**, because a revision replaces the verdict
 * rather than patching it. A rating without words is a legitimate review
 * (ADR 0047), so "no body" has to be able to mean "I have removed what I said"
 * - and optional fields on the update would take that meaning away.
 *
 * The rating is required and the words are not: somebody who gives four stars
 * and nothing else has still reviewed the thing.
 *
 * Whether this person may review at all is not here. That is a question about
 * their orders rather than about the payload, and a form request that went
 * looking for one would be doing the action's job (`apps/api/CLAUDE.md`
 * section 7).
 */
final class ReviewRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],

            // Long enough for somebody to explain a camera lens, short enough
            // that a page of reviews is still a page. The column takes more; a
            // request does not need to.
            'body' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function rating(): int
    {
        return $this->integer('rating');
    }

    /**
     * The words, or null when there were none.
     *
     * Trimmed, and an empty string becomes null rather than an empty review:
     * the database refuses a blank body, and a space bar is not a verdict.
     */
    public function body(): ?string
    {
        $body = trim((string) $this->input('body', ''));

        return $body === '' ? null : $body;
    }
}
