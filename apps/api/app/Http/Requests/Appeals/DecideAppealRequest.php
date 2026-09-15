<?php

declare(strict_types=1);

namespace App\Http\Requests\Appeals;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What the platform decided about an appeal, and why (ADR 0059).
 *
 * **The note is required either way**, as it is for a report. Upholding sends
 * it to somebody who is about to get their shop or their listing back, and
 * dismissing sends it to somebody who is not - and the second needs it more:
 * an appeal refused without a word is the marketplace declining to explain
 * twice.
 *
 * `upheld` is a boolean rather than two endpoints, unlike approving and
 * rejecting a shop. Those are different decisions with different requirements;
 * these two carry exactly the same fields.
 */
final class DecideAppealRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'upheld' => ['required', 'boolean'],
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
