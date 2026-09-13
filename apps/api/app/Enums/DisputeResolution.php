<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a dispute ended, and therefore where the money went.
 *
 * Two cases, because the money is held on the platform and there are exactly
 * two places it can go from there (ADR 0041): back to the buyer, or on to the
 * shop. There is no third answer while partial refunds do not exist, and
 * inventing one here would be a case nothing can arrive at.
 *
 * The words are the ones the rest of the application already uses.
 * `refunded_at` and `transferred_at` are the columns these produce, and ADR
 * 0041 calls completion "releasing" the money throughout.
 */
enum DisputeResolution: string
{
    /** The buyer is made whole, and the order is cancelled. */
    case Refunded = 'refunded';

    /** The shop is paid, and the order is completed. */
    case Released = 'released';

    /** What to call it, in the words both parties are shown. */
    public function label(): string
    {
        return match ($this) {
            self::Refunded => 'Refunded to the buyer',
            self::Released => 'Released to the shop',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
