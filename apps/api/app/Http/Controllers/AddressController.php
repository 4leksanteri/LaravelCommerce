<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesAuthenticatedUser;
use App\Http\Requests\Addresses\StoreAddressRequest;
use App\Http\Requests\Addresses\UpdateAddressRequest;
use App\Http\Resources\AddressCollection;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Somebody's own address book.
 *
 * Every lookup starts from `$user->addresses()`, so another person's address is
 * never in the query and there is no id here to substitute - the same reasoning
 * that leaves the cart and a buyer's orders without a policy (ADR 0008).
 *
 * There is deliberately no `verified` middleware. Saving an address is
 * preparation, and the check belongs at checkout, which has it.
 */
final class AddressController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function index(Request $request): AddressCollection
    {
        return new AddressCollection($this->authenticatedUser($request)->addresses()->get());
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $address = new Address;

        $address->forceFill([
            ...$request->safe()->only([
                'name', 'line1', 'line2', 'city', 'region', 'postal_code', 'country', 'phone',
            ]),
            // From the session, never from the body.
            'user_id' => $this->authenticatedUser($request)->id,
        ])->save();

        return (new AddressResource($address))->response()->setStatusCode(201);
    }

    /**
     * @throws ModelNotFoundException<Address>
     */
    public function update(UpdateAddressRequest $request, string $address): JsonResponse
    {
        $stored = $this->address($request, $address);

        $stored->fill($request->safe()->only([
            'name', 'line1', 'line2', 'city', 'region', 'postal_code', 'country', 'phone',
        ]))->save();

        return (new AddressResource($stored))->response();
    }

    /**
     * A hard delete, and it is safe.
     *
     * Nothing references an address: an order froze its own copy rather than
     * pointing at one (ADR 0021), so removing this takes nothing with it.
     *
     * @throws ModelNotFoundException<Address>
     */
    public function destroy(Request $request, string $address): Response
    {
        $this->address($request, $address)->delete();

        return response()->noContent();
    }

    /**
     * Resolved through the caller's own book, so somebody else's is a **404**
     * rather than a 403 - a 403 would confirm the id names a real address.
     *
     * There is no route model binding on these routes: implicit binding
     * resolves globally, and `{address}` would then be anybody's.
     *
     * @throws ModelNotFoundException<Address>
     */
    private function address(Request $request, string $id): Address
    {
        return $this->authenticatedUser($request)->addresses()->findOrFail((int) $id);
    }
}
