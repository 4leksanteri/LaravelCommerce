<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a person is to the platform.
 *
 * **Selling is not a role.** Somebody sells by having a `sellers` row, not by
 * being a `seller`. A shop is a thing with a name, a currency and an approval
 * state; a role is not, and conflating them means every seller question turns
 * into two lookups that can disagree.
 *
 * So a shop owner is a `Customer` who also has a seller. They buy from other
 * shops with the same account, which is the behaviour a marketplace wants.
 */
enum UserRole: string
{
    case Customer = 'customer';

    /** Reviews seller applications and handles disputes. */
    case Staff = 'staff';

    /** Everything staff can do, plus whatever administration grows to mean. */
    case Admin = 'admin';

    /**
     * Whether this role acts on behalf of the platform rather than for itself.
     *
     * Asked instead of comparing against a list of cases at each call site, so
     * that adding a role means editing this method and nothing else.
     */
    public function isPlatformStaff(): bool
    {
        return $this === self::Staff || $this === self::Admin;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
