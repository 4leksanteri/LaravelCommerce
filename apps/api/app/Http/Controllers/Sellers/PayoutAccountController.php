<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sellers;

use App\Actions\Payouts\AttachIdentityDocument;
use App\Actions\Payouts\OpenPayoutAccount;
use App\Actions\Payouts\UpdatePayoutDetails;
use App\Exceptions\NoPayoutAccountException;
use App\Http\Controllers\Concerns\ResolvesCurrentSeller;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payouts\OpenPayoutAccountRequest;
use App\Http\Requests\Payouts\StoreIdentityDocumentRequest;
use App\Http\Requests\Payouts\UpdatePayoutDetailsRequest;
use App\Http\Resources\PayoutAccountResource;
use App\Models\PayoutAccount;
use App\Models\Seller;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * How the signed-in person's shop gets paid (ADR 0031).
 *
 * A singleton under the shop, as the shop itself is: one payout account per
 * shop, so there is no id in the path and nothing for a caller to substitute.
 * Behind `seller`, so a caller without a shop never arrives.
 *
 * Reading is the stored copy of what Stripe said, and never calls Stripe: the
 * page loads while Stripe is slow, and on a stack with no Stripe key at all.
 * Every write calls Stripe, and the account Stripe hands back is what the
 * response is drawn from.
 */
final class PayoutAccountController extends Controller
{
    use ResolvesCurrentSeller;

    public function show(Request $request): PayoutAccountResource
    {
        return new PayoutAccountResource($this->ownShop($request)->load('payoutAccount'));
    }

    /**
     * Opens the shop's account at Stripe, with Stripe's terms accepted.
     */
    #[Response(status: 409, description: 'The shop has not been approved yet, or already has a payout account.', type: 'array{message: string}')]
    public function store(OpenPayoutAccountRequest $request, OpenPayoutAccount $open): JsonResponse
    {
        $seller = $this->ownShop($request);

        $account = $open->handle($seller, $request->country(), (string) $request->ip(), $request->userAgent());

        return (new PayoutAccountResource($seller->setRelation('payoutAccount', $account)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Sends the seller's details on to Stripe. Partial: what is absent is left
     * as Stripe has it.
     */
    #[Response(status: 409, description: 'The shop has not opened a payout account yet.', type: 'array{message: string}')]
    public function update(UpdatePayoutDetailsRequest $request, UpdatePayoutDetails $update): PayoutAccountResource
    {
        $seller = $this->ownShop($request);

        $account = $update->handle(
            $this->payoutAccount($seller),
            $request->validated(),
            (string) $request->ip(),
            $request->userAgent(),
        );

        return new PayoutAccountResource($seller->setRelation('payoutAccount', $account));
    }

    /**
     * Passes an identity document to Stripe. Multipart, and not kept here.
     */
    #[Response(status: 409, description: 'The shop has not opened a payout account yet.', type: 'array{message: string}')]
    public function identityDocument(StoreIdentityDocumentRequest $request, AttachIdentityDocument $attach): PayoutAccountResource
    {
        $seller = $this->ownShop($request);
        $account = $this->payoutAccount($seller);

        $front = $request->file('front');
        $back = $request->file('back');

        // Validated as single files already. This gives the value a type, and
        // fails loudly if the rules and this ever stop agreeing.
        if (! $front instanceof UploadedFile) {
            throw new RuntimeException('The front of the document was validated but is not an uploaded file.');
        }

        $account = $attach->handle($account, $front, $back instanceof UploadedFile ? $back : null);

        return new PayoutAccountResource($seller->setRelation('payoutAccount', $account));
    }

    /**
     * The caller's shop, with the policy asked about it.
     *
     * The `seller` middleware resolved it from the caller's own account, so it
     * is theirs by construction. The policy is asked anyway, as ShopController
     * asks it, because that is where the rule is written down. Setting up how
     * a shop is paid is managing the shop, and `update` is that permission.
     */
    private function ownShop(Request $request): Seller
    {
        $seller = $this->currentSeller($request);

        $this->authorize('update', $seller);

        return $seller;
    }

    /**
     * @throws NoPayoutAccountException
     */
    private function payoutAccount(Seller $seller): PayoutAccount
    {
        $account = $seller->payoutAccount;

        if (! $account instanceof PayoutAccount) {
            throw NoPayoutAccountException::forShop();
        }

        return $account;
    }
}
