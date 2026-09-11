<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\PayoutCountry;
use App\Enums\PayoutField;
use App\Enums\PayoutStatus;
use App\Models\PayoutAccount;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Where a shop stands with getting paid, as its owner sees it.
 *
 * Over the **shop** rather than its account, because the account may not
 * exist yet and the answer still does: `not_started`, and whether one can be
 * opened. Every key is present in every state, so a client reads it without
 * first checking what exists.
 *
 * Stripe's requirement paths do not reach the client as a form. `due` is in
 * this API's own words - PayoutField - so the frontend draws one date of birth
 * rather than three of Stripe's paths. What no field answers is listed in
 * `unsupported`, verbatim, so it is visible rather than silently missing.
 */
final class PayoutAccountResource extends JsonResource
{
    public function __construct(private readonly Seller $seller)
    {
        parent::__construct($seller);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $account = $this->seller->payoutAccount;

        // The `@var` blocks are for the contract. The generator cannot see
        // through array_filter and friends, and published these as `unknown[]`
        // without them - which the frontend would have had to cast.
        return [
            'status' => $this->status($account),

            /** @var string|null */
            'country' => $account?->country,

            /** @var list<PayoutField> */
            'due' => $this->due($account),

            /** @var list<string> */
            'unsupported' => $this->unsupported($account),

            /** @var list<array{field: PayoutField|null, requirement: string, reason: string}> */
            'errors' => $this->errors($account),

            // When Stripe will restrict the account if what is due has not
            // arrived. Null when nothing is due, or nothing is due by a date.
            'due_by' => $account?->requirements_due_at?->toIso8601String(),

            'bank_account_last4' => $account?->bank_account_last4,
            'can_open' => $this->canOpen($account),

            /**
             * Sent rather than kept in the frontend, so the list of where an
             * account may be opened has one home.
             *
             * @var list<PayoutCountry>
             */
            'countries' => PayoutCountry::cases(),
        ];
    }

    private function status(?PayoutAccount $account): PayoutStatus
    {
        return $account?->status() ?? PayoutStatus::NotStarted;
    }

    /**
     * What the seller is asked for, in the order a form asks it.
     *
     * @return list<PayoutField>
     */
    private function due(?PayoutAccount $account): array
    {
        if (! $account instanceof PayoutAccount) {
            return [];
        }

        $answered = array_map(PayoutField::answering(...), $account->outstandingRequirements());

        return array_values(array_filter(
            PayoutField::cases(),
            static fn (PayoutField $field): bool => in_array($field, $answered, true),
        ));
    }

    /**
     * Stripe's requirements that no field here answers, as Stripe names them.
     *
     * @return list<string>
     */
    private function unsupported(?PayoutAccount $account): array
    {
        if (! $account instanceof PayoutAccount) {
            return [];
        }

        return array_values(array_filter(
            $account->outstandingRequirements(),
            static fn (string $requirement): bool => PayoutField::answering($requirement) === null,
        ));
    }

    /**
     * What Stripe could not verify, beside the field it is about.
     *
     * @return list<array{field: PayoutField|null, requirement: string, reason: string}>
     */
    private function errors(?PayoutAccount $account): array
    {
        if (! $account instanceof PayoutAccount) {
            return [];
        }

        return array_map(static fn (array $error): array => [
            'field' => PayoutField::answering($error['requirement']),
            'requirement' => $error['requirement'],
            'reason' => $error['reason'],
        ], $account->requirementErrors());
    }

    /**
     * The two conditions OpenPayoutAccount refuses on, read the same way.
     * PayoutAccountTest holds this answer and that action to each other.
     */
    private function canOpen(?PayoutAccount $account): bool
    {
        return $account === null && $this->seller->isPublic();
    }
}
