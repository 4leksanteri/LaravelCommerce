<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a listing is on sale.
 *
 * Two cases, and there is deliberately no `archived`. Unpublishing already
 * means "keep it, stop selling it", which is what an archive would be for, and
 * genuine removal is a soft delete. A third case would just be a second way to
 * say draft.
 *
 * Being published is necessary for a shopper to see a product and is not
 * sufficient: the shop has to be approved too. `Product::scopePublic()` is
 * where those two are combined, once.
 */
enum ProductStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function isPublished(): bool
    {
        return $this === self::Published;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
