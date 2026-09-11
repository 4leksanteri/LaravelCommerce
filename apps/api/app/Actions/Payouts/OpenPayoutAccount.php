<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Enums\PayoutCountry;
use App\Exceptions\PayoutAccountNotOpenableException;
use App\Models\PayoutAccount;
use App\Models\Seller;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stripe\Account;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

/**
 * Opens a shop's connected account at Stripe.
 *
 * The configuration is ADR 0015's, and every line of it is a decision:
 *
 *   requirement_collection  application   this platform collects verification,
 *                                         on its own pages rather than Stripe's
 *   stripe_dashboard        none          the seller never signs in to Stripe
 *   losses.payments         application   the platform carries what goes wrong
 *   fees.payer              application   and pays Stripe's fees
 *   capabilities            transfers     and nothing else - see below
 *
 * **Only `transfers` is requested.** ADR 0015 charges on the platform and
 * transfers to the shop when an order completes, so a shop's account is never
 * charged against and never needs `card_payments`. Asking for less is also
 * less to verify, because card payments bring requirements of their own.
 *
 * **Individuals only, for now.** A company needs its representatives, owners
 * and directors, each a Person at Stripe with a verification of their own, and
 * that is a separate build (ADR 0031).
 *
 * Two answers are the platform's rather than the seller's, because the
 * platform knows them: what kind of business this is, and a description of
 * it. Stripe asks every account for both, and every shop here sells the same
 * kind of thing (ADR 0019).
 */
final class OpenPayoutAccount
{
    /** Used Merchandise and Secondhand Stores, which is what every shop here is. */
    private const string MERCHANT_CATEGORY_CODE = '5931';

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly SyncPayoutAccount $sync,
    ) {}

    /**
     * @throws PayoutAccountNotOpenableException
     * @throws ValidationException when Stripe will not open an account in that country
     */
    public function handle(Seller $seller, PayoutCountry $country, string $ip, ?string $userAgent): PayoutAccount
    {
        return DB::transaction(function () use ($seller, $country, $ip, $userAgent): PayoutAccount {
            // Locked, so two requests arriving together are serialised rather
            // than both finding no account and both opening one at Stripe. The
            // unique index on seller_id would refuse the second row - but only
            // after Stripe had made an account nothing would ever point at.
            //
            // That does hold the lock across a call to Stripe. It is one row,
            // the shop's own, and nothing else is waiting on it.
            $locked = Seller::query()->lockForUpdate()->findOrFail($seller->id);

            if (! $locked->isPublic()) {
                throw PayoutAccountNotOpenableException::shopNotApproved();
            }

            if ($locked->payoutAccount()->exists()) {
                throw PayoutAccountNotOpenableException::alreadyOpen();
            }

            $acceptedAt = now();

            $opened = $this->open($locked, $country, $acceptedAt, $ip, $userAgent);

            $account = new PayoutAccount;

            $account->forceFill([
                'seller_id' => $locked->id,
                'stripe_account_id' => $opened->id,
                'country' => $country->value,
                'terms_accepted_at' => $acceptedAt,
                'terms_accepted_ip' => $ip,
                'terms_accepted_user_agent' => $userAgent,
            ]);

            return $this->sync->handle($account, $opened);
        });
    }

    /**
     * @throws ValidationException
     */
    private function open(
        Seller $seller,
        PayoutCountry $country,
        CarbonInterface $acceptedAt,
        string $ip,
        ?string $userAgent,
    ): Account {
        // Accepted on our page, so recorded by us rather than by Stripe
        // (ADR 0015). The user agent is optional to Stripe and not always sent.
        $termsAcceptance = ['date' => $acceptedAt->getTimestamp(), 'ip' => $ip];

        if ($userAgent !== null) {
            $termsAcceptance['user_agent'] = $userAgent;
        }

        try {
            return $this->stripe->accounts->create([
                'country' => $country->value,
                'business_type' => 'individual',
                'controller' => [
                    'fees' => ['payer' => 'application'],
                    'losses' => ['payments' => 'application'],
                    'requirement_collection' => 'application',
                    'stripe_dashboard' => ['type' => 'none'],
                ],
                'capabilities' => [
                    'transfers' => ['requested' => true],
                ],
                'business_profile' => [
                    'mcc' => self::MERCHANT_CATEGORY_CODE,
                    'product_description' => sprintf(
                        'Secondhand equipment, sold by the shop "%s" on %s.',
                        $seller->shop_name,
                        config('app.name'),
                    ),
                ],
                'tos_acceptance' => $termsAcceptance,
                // So an account found at Stripe can be traced back to its shop
                // without this database.
                'metadata' => ['seller_id' => (string) $seller->id],
            ], [
                // The one call here that creates something. If the SDK retries
                // it after a network failure, the key makes the retry the same
                // request to Stripe rather than a second account. The SDK adds
                // one itself only when retries are switched on globally, which
                // they are not here, so it is sent explicitly.
                'idempotency_key' => (string) Str::uuid(),
            ]);
        } catch (InvalidRequestException $refusal) {
            // The country is the one thing the seller chose. Anything else
            // Stripe objects to is in parameters this class wrote, and is this
            // application's mistake to surface rather than the seller's to fix.
            if ($refusal->getStripeParam() !== 'country') {
                throw $refusal;
            }

            throw ValidationException::withMessages(['country' => $refusal->getMessage()]);
        }
    }
}
