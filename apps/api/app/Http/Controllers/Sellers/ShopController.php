<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sellers;

use App\Actions\Sellers\UpdateShopDetails;
use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Concerns\ResolvesCurrentSeller;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sellers\UpdateShopRequest;
use App\Http\Resources\SellerResource;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in person's own shop.
 *
 * A singleton: one shop per account (ADR 0007), so there is no id in the path.
 */
final class ShopController extends Controller
{
    use ResolvesAuthenticatedUser;
    use ResolvesCurrentSeller;

    /**
     * Answers 200 with `data: null` when there is no application yet.
     *
     * This is the one seller endpoint deliberately **not** behind the `seller`
     * middleware, because it is the question "do I have a shop" and the answer
     * "no" is not an error. Almost every account on a marketplace has no shop,
     * and the frontend asks this on every page load to decide what the
     * navigation says - answering 403 would mean wrapping a routine question
     * in a try/catch and filling the logs with refusals that mean nothing.
     */
    public function show(Request $request): JsonResponse
    {
        $seller = $this->authenticatedUser($request)->seller()->first();

        if (! $seller instanceof Seller) {
            return new JsonResponse(['data' => null]);
        }

        return (new SellerResource($seller))->response();
    }

    /**
     * Behind the `seller` middleware, so there is no "you have no shop" branch
     * here: a caller without one never arrives.
     */
    public function update(UpdateShopRequest $request, UpdateShopDetails $update): JsonResponse
    {
        $seller = $this->currentSeller($request);

        // The middleware resolved this shop from the caller's own account, so
        // it is theirs by construction. The policy is asked anyway, because
        // "it is theirs by construction" is a property of today's routing and
        // the policy is the place that rule is actually written down.
        $this->authorize('update', $seller);

        return (new SellerResource(
            $update->handle($seller, $request->safe()->only(['shop_name', 'description', 'contact_email']))
        ))->response();
    }
}
