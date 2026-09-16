<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | How long an order may wait for its seller
    |--------------------------------------------------------------------------
    |
    | A pending order holds stock that nobody else can buy, and nothing releases
    | it if neither party ever acts. `orders:expire` cancels orders that have
    | sat here longer than this and gives the stock back.
    |
    | Three days rather than one, deliberately. Today `pending` means "waiting
    | for the seller to accept" - not "unpaid" - so the clock is running on a
    | person, and a shorter window cancels real orders from patient buyers
    | because a small shop was closed for the weekend.
    |
    | **Payments arrived, and this window kept its days** (ADR 0042). What was
    | predicted here was a shortening; what happened instead was a split. An
    | order nobody has paid for is not waiting on a person at all, so it goes
    | in minutes on a clock of its own - `payments.unpaid_expires_after_minutes`
    | - and this one still measures what it always measured: how long a shop
    | gets to answer.
    |
    */

    'pending_expires_after_hours' => (int) env('ORDER_PENDING_EXPIRES_AFTER_HOURS', 72),

    /*
    |--------------------------------------------------------------------------
    | How long a shipped order waits for its buyer
    |--------------------------------------------------------------------------
    |
    | Completion is the buyer confirming they received it, and buyers forget.
    | `orders:auto-complete` does it on their behalf this long after shipping.
    |
    | Operational tuning - a marketplace shipping across a continent wants a
    | different number from one delivering by bicycle - so it comes from the
    | environment.
    |
    */

    'auto_complete_after_days' => (int) env('ORDER_AUTO_COMPLETE_AFTER_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | What a buyer can do about a late parcel
    |--------------------------------------------------------------------------
    |
    | A buyer whose shipment has not arrived can push the deadline back, so
    | that a slow courier does not end with the marketplace declaring a parcel
    | received. Capped, because otherwise it defers forever.
    |
    | These two are deliberately **not** environment variables. They are
    | product design rather than deployment tuning: changing how forgiving the
    | marketplace is toward late shipments is a decision to make once and write
    | down, not a value to differ between staging and production.
    |
    | 14 + 7 + 7 gives a buyer 28 days from posting before the marketplace
    | assumes delivery. Past that they need a dispute - which ADR 0051 built,
    | and which ADR 0061 carried past completion.
    |
    */

    'completion_extension_days' => 7,

    'max_completion_extensions' => 2,

    /*
    |--------------------------------------------------------------------------
    | How long a buyer may dispute after an order has completed
    |--------------------------------------------------------------------------
    |
    | Completion used to be the end of it. The money reached the shop and
    | nothing could bring it back, so ADR 0051 made the dispute window exactly
    | as wide as the money was held. ADR 0061 built the reversal that bound was
    | waiting on, and this is how far past completion the window now reaches.
    |
    | **It is bounded because the alternative is a shop that is never paid.** A
    | marketplace where money can be taken back at any time is one where a shop
    | can never treat its takings as its own, which is worse for honest sellers
    | than the abuse it would catch. Thirty days is long enough to open a parcel
    | that sat in a hallway, and short enough that a season's earnings settle.
    |
    | Not an environment variable, for the reason the two above it are not: how
    | forgiving this marketplace is toward a buyer who confirmed too early is
    | product design, decided once and written down, rather than something to
    | differ between staging and production.
    |
    */

    'dispute_after_completion_days' => 30,

];
