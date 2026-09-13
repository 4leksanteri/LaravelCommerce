<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\DisputeResolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What the platform decided, and why.
 *
 * **The note is required, not optional.** Both parties are sent it, and a
 * decision about somebody's money that arrives without a reason is the support
 * ticket this field exists to prevent. `sellers.rejection_reason` is required
 * for the same reason, and `disputes_resolution_is_whole` enforces it in the
 * database rather than trusting this class alone.
 *
 * The resolution is validated against the enum rather than against a list of
 * strings, so adding a third outcome is a change in one place.
 */
final class ResolveDisputeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'resolution' => ['required', Rule::enum(DisputeResolution::class)],
            'note' => ['required', 'string', 'max:2000'],
        ];
    }

    public function resolution(): DisputeResolution
    {
        return DisputeResolution::from((string) $this->input('resolution'));
    }

    public function note(): string
    {
        return trim((string) $this->input('note', ''));
    }
}
