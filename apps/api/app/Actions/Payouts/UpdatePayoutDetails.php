<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Enums\PayoutField;
use App\Models\PayoutAccount;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

/**
 * Sends what the seller told us on to Stripe, and keeps none of it.
 *
 * Names, a date of birth, a home address, an ID number and an IBAN arrive
 * here, are rewritten into Stripe's parameters, and leave. Nothing is written
 * down but Stripe's answer: which requirements remain, and the last four
 * digits of the bank account (ADR 0031).
 *
 * `#[SensitiveParameter]` on the details, so a stack trace through this class
 * records that an argument was there and not what it was. The PHP setting that
 * keeps arguments out of every trace is in docker/api/conf.d; this is the
 * part that still holds wherever that file is not.
 *
 * **A refusal goes beside the field that caused it** where it can. Stripe
 * names the parameter it objected to and PayoutField maps it back to a form
 * field, so an IBAN Stripe will not take is a 422 on `iban`. A refusal naming a
 * parameter no field sends is this application's mistake rather than the
 * seller's, and it is rethrown as one rather than shown to them as theirs.
 */
final class UpdatePayoutDetails
{
    /** Fields that are already named as Stripe names them, under `individual`. */
    private const array INDIVIDUAL_FIELDS = ['first_name', 'last_name', 'email', 'phone', 'id_number'];

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly SyncPayoutAccount $sync,
    ) {}

    /**
     * @param  array<string, mixed>  $details  validated, keyed by PayoutField values
     *
     * @throws ValidationException
     */
    public function handle(
        PayoutAccount $account,
        #[SensitiveParameter] array $details,
        string $ip,
        ?string $userAgent,
    ): PayoutAccount {
        $now = now();

        try {
            $updated = $this->stripe->accounts->update(
                $account->stripe_account_id,
                $this->parameters($account, $details, $now, $ip, $userAgent),
            );
        } catch (InvalidRequestException $refusal) {
            $field = PayoutField::answeringParameter($refusal->getStripeParam());

            if ($field === null) {
                throw $refusal;
            }

            throw ValidationException::withMessages([$field->value => $refusal->getMessage()]);
        }

        // Stripe asks for its terms again when it changes them. The newest
        // acceptance is the one that counts, so it replaces the first.
        if (array_key_exists(PayoutField::Terms->value, $details)) {
            $account->forceFill([
                'terms_accepted_at' => $now,
                'terms_accepted_ip' => $ip,
                'terms_accepted_user_agent' => $userAgent,
            ]);
        }

        return $this->sync->handle($account, $updated);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function parameters(
        PayoutAccount $account,
        #[SensitiveParameter] array $details,
        CarbonInterface $now,
        string $ip,
        ?string $userAgent,
    ): array {
        $individual = array_intersect_key($details, array_flip(self::INDIVIDUAL_FIELDS));

        if (is_string($details[PayoutField::DateOfBirth->value] ?? null)) {
            // Validated as Y-m-d, and Stripe takes the three parts separately.
            [$year, $month, $day] = array_map(intval(...), explode('-', $details[PayoutField::DateOfBirth->value]));

            $individual['dob'] = ['day' => $day, 'month' => $month, 'year' => $year];
        }

        if (is_array($details[PayoutField::Address->value] ?? null)) {
            $individual['address'] = [
                ...array_filter(
                    $details[PayoutField::Address->value],
                    static fn (mixed $part): bool => $part !== null && $part !== '',
                ),
                // Not asked for. Somebody opens their account in the country
                // they live in, and asking again would only let the two differ.
                'country' => $account->country,
            ];
        }

        $parameters = $individual === [] ? [] : ['individual' => $individual];

        if (is_string($details[PayoutField::Iban->value] ?? null)) {
            $iban = $details[PayoutField::Iban->value];

            $parameters['external_account'] = [
                'object' => 'bank_account',
                // The bank's country, which is what an IBAN starts with, and
                // which need not be the seller's: a Finn may bank in Estonia.
                'country' => substr($iban, 0, 2),
                // Payouts arrive in the shop's own currency, the one everything
                // it sells is priced in (ADR 0004).
                'currency' => strtolower($account->seller->currency->value),
                'account_number' => $iban,
            ];
        }

        if (array_key_exists(PayoutField::Terms->value, $details)) {
            $parameters['tos_acceptance'] = ['date' => $now->getTimestamp(), 'ip' => $ip];

            if ($userAgent !== null) {
                $parameters['tos_acceptance']['user_agent'] = $userAgent;
            }
        }

        return $parameters;
    }
}
