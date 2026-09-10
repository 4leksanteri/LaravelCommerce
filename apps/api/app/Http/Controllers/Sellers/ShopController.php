<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sellers;

use App\Actions\Sellers\UpdateShopDetails;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sellers\UpdateShopRequest;
use App\Http\Resources\SellerResource;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The signed-in person's own shop.
 *
 * A singleton: one shop per account (ADR 0007), so there is no id in the path.
 */
final class ShopController extends Controller
{
    /**
     * Answers 200 with `data: null` when there is no application yet, not 404.
     *
     * Having no shop is a normal state for almost every account on a
     * marketplace, and the frontend asks this question on every page load to
     * decide what the navigation says. Making the common answer an error would
     * mean every caller wrapping a routine question in a try/catch, and it
     * would put a stream of 404s in the logs that mean nothing.
     *
     * 404 stays available for a shop that genuinely is not there - see the
     * public endpoint.
     */
    public function show(Request $request): JsonResponse
    {
        $seller = $this->currentUser($request)->seller()->first();

        if (! $seller instanceof Seller) {
            return new JsonResponse(['data' => null]);
        }

        return (new SellerResource($seller))->response();
    }

    public function update(UpdateShopRequest $request, UpdateShopDetails $update): JsonResponse
    {
        $seller = $this->currentUser($request)->seller()->first();

        if (! $seller instanceof Seller) {
            throw new HttpException(404, 'This account has no shop to edit.');
        }

        // Authorization is the policy's answer, asked here rather than assumed
        // from having loaded the row through the user's own relation. The
        // relation makes it true today; the policy is what says so.
        $this->authorize('update', $seller);

        return (new SellerResource(
            $update->handle($seller, $request->safe()->only(['shop_name', 'description', 'contact_email']))
        ))->response();
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('The guard authenticated a request without a user.');
        }

        return $user;
    }
}
