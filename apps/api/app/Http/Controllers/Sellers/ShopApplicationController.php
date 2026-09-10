<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sellers;

use App\Actions\Sellers\ApplyToSell;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sellers\ApplyToSellRequest;
use App\Http\Resources\SellerResource;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Applying to open a shop.
 *
 * Behind `auth:sanctum` and `verified`: an unverified address is one nobody
 * has shown they can read, and the whole review conversation - approved,
 * rejected, here is why - happens by email.
 */
final class ShopApplicationController extends Controller
{
    public function __invoke(ApplyToSellRequest $request, ApplyToSell $apply): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('The guard authenticated a request without a user.');
        }

        $existing = $user->seller()->first();

        // A pending application is already in the queue and an approved shop
        // is already trading; resubmitting either would either duplicate the
        // reviewer's work or quietly take a live shop offline. Only a rejected
        // application may be sent again, which ApplyToSell handles by reusing
        // the same row.
        if ($existing instanceof Seller && ! $existing->status->isReviewed()) {
            throw new HttpException(409, 'An application for this account is already awaiting review.');
        }

        if ($existing instanceof Seller && $existing->isPublic()) {
            throw new HttpException(409, 'This account already has an approved shop.');
        }

        $seller = $apply->handle($user, [
            'shop_name' => $request->string('shop_name')->toString(),
            'description' => $request->input('description'),
            'contact_email' => $request->string('contact_email')->toString(),
            'currency' => $request->string('currency')->toString(),
        ]);

        return (new SellerResource($seller))->response()->setStatusCode(201);
    }
}
