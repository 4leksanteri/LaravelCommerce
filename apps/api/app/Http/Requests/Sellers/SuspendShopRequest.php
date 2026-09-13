<?php

declare(strict_types=1);

namespace App\Http\Requests\Sellers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Why a shop is being stopped.
 *
 * Required, and the only field. The shop is sent it and has nothing else to go
 * on: a suspension with no reason tells somebody their business is closed
 * without telling them what to fix, which is the same argument
 * `RejectSellerRequest` makes for a rejection. The table refuses a blank one
 * too.
 *
 * Whether this shop *can* be suspended is not here. That is a question about
 * where the shop has got to rather than about the payload, and it belongs in
 * the action (`apps/api/CLAUDE.md` section 7).
 */
final class SuspendShopRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // The same floor `RejectSellerRequest` sets, for the same reason
            // and with more riding on it: the shop reads this and is expected
            // to act on it, and a business stopped with one word has been told
            // nothing it can do anything about.
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
