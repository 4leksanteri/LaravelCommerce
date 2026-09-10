<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sellers;

use App\Actions\Sellers\ApplyToSell;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sellers\ApplyToSellRequest;
use App\Http\Resources\SellerResource;
use Illuminate\Http\JsonResponse;

/**
 * Applying to open a shop.
 *
 * Behind `auth:sanctum` and `verified`: an unverified address is one nobody
 * has shown they can read, and the whole review conversation - approved,
 * rejected, here is why - happens by email.
 *
 * The rules about when an application may be made are `ApplyToSell`'s, and
 * refusing one is a 409 that bootstrap/app.php renders from the domain
 * exception. Neither belongs here: a controller coordinates.
 */
final class ShopApplicationController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function __invoke(ApplyToSellRequest $request, ApplyToSell $apply): JsonResponse
    {
        $seller = $apply->handle($this->authenticatedUser($request), [
            'shop_name' => $request->string('shop_name')->toString(),
            'description' => $request->input('description'),
            'contact_email' => $request->string('contact_email')->toString(),
            'currency' => $request->string('currency')->toString(),
        ]);

        return (new SellerResource($seller))->response()->setStatusCode(201);
    }
}
