<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | What the marketplace takes
    |--------------------------------------------------------------------------
    |
    | In basis points: 500 is five per cent. Deducted from the transfer when an
    | order completes, so the shop receives its total less this, and the
    | platform keeps the difference on its own balance (ADR 0015).
    |
    | Basis points rather than a percentage, because a percentage invites a
    | float and money is never a float (ADR 0004). The fee is computed in
    | integer minor units, and the rounding is deliberate and tested.
    |
    | Commercial tuning rather than product design, so it comes from the
    | environment: a marketplace changing what it charges should not need a
    | deploy of new code.
    |
    */

    'platform_fee_bps' => (int) env('STRIPE_PLATFORM_FEE_BPS', 500),

    /*
    |--------------------------------------------------------------------------
    | How long an unpaid order holds its stock
    |--------------------------------------------------------------------------
    |
    | An order is written before it is paid, and it takes stock when it is
    | written (ADR 0011). Until payments existed, `pending` meant "waiting for
    | the seller" and orders waited three days (config/orders.php).
    |
    | An unpaid order is a different thing on a different clock: nobody has
    | committed to anything, and it is holding the last of something somebody
    | else wants. Minutes, not days - long enough to finish typing a card in,
    | and this is the hold ADR 0010 said the cart was missing.
    |
    */

    'unpaid_expires_after_minutes' => (int) env('ORDER_UNPAID_EXPIRES_AFTER_MINUTES', 30),

];
