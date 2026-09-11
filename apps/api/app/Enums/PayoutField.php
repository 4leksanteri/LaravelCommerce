<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The details this API can collect for a payout account, and which of Stripe's
 * requirements each one answers.
 *
 * Stripe says what it needs as a list of dotted paths - `individual.dob.day`,
 * `external_account` - that varies by country and changes over time
 * (ADR 0015). This is the translation between that list and a form: three of
 * Stripe's paths are one date of birth, and five are one address.
 *
 * A requirement that matches no case here is **unsupported**, and the resource
 * names it rather than dropping it. A seller asked for something this API
 * cannot take is stuck, and a list of what is missing is how that gets noticed
 * and built rather than discovered by somebody who cannot get paid.
 */
enum PayoutField: string
{
    case FirstName = 'first_name';
    case LastName = 'last_name';
    case Email = 'email';
    case Phone = 'phone';
    case DateOfBirth = 'date_of_birth';
    case Address = 'address';
    case IdNumber = 'id_number';
    case IdentityDocument = 'identity_document';
    case Iban = 'iban';
    case Terms = 'terms';

    /** The Stripe requirement this field answers, along with everything beneath it. */
    public function requirement(): string
    {
        return match ($this) {
            self::FirstName => 'individual.first_name',
            self::LastName => 'individual.last_name',
            self::Email => 'individual.email',
            self::Phone => 'individual.phone',
            self::DateOfBirth => 'individual.dob',
            self::Address => 'individual.address',
            self::IdNumber => 'individual.id_number',
            self::IdentityDocument => 'individual.verification.document',
            self::Iban => 'external_account',
            self::Terms => 'tos_acceptance',
        };
    }

    /**
     * Which field answers a requirement, or null when none does.
     *
     * Matched on whole segments: `individual.dob.year` belongs to DateOfBirth,
     * and `individual.verification.additional_document` does not belong to
     * IdentityDocument, although a plain prefix match would say it did.
     */
    public static function answering(string $requirement): ?self
    {
        foreach (self::cases() as $field) {
            $path = $field->requirement();

            if ($requirement === $path || str_starts_with($requirement, $path.'.')) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The same, for the parameter Stripe names when it refuses a request.
     *
     * Stripe reports those form-encoded - `individual[dob][year]` - rather than
     * in the dotted form it uses for requirements, so they are rewritten into
     * one before being matched.
     */
    public static function answeringParameter(?string $parameter): ?self
    {
        if ($parameter === null || $parameter === '') {
            return null;
        }

        return self::answering(str_replace(['][', '[', ']'], ['.', '.', ''], $parameter));
    }
}
