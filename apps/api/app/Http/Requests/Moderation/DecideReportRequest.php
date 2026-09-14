<?php

declare(strict_types=1);

namespace App\Http\Requests\Moderation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What the platform decided about a report, and why (ADR 0054).
 *
 * **The note is required either way**, and that is deliberate. When a report is
 * upheld the note becomes the reason the seller or the reviewer is sent, and a
 * takedown with no explanation is somebody's listing gone with nothing to fix.
 * When it is dismissed nobody is written to, but the note is what the next
 * member of staff reads when the same thing is reported again - a queue whose
 * dismissals say nothing makes everybody re-decide from scratch.
 *
 * `upheld` is a boolean rather than two endpoints, unlike approving and
 * rejecting a shop. Those are different decisions with different requirements -
 * a rejection carries a reason and an approval does not - and these two carry
 * exactly the same fields.
 */
final class DecideReportRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'upheld' => ['required', 'boolean'],

            // The same floor a rejection and a suspension have, for the same
            // reason: this is read by somebody who has to act on it.
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function upheld(): bool
    {
        return $this->boolean('upheld');
    }

    public function note(): string
    {
        return trim((string) $this->input('note', ''));
    }
}
