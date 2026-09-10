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
    | **This window shortens dramatically when payments arrive**, because
    | `pending` will then mean "nobody has paid", and holding stock for three
    | days on an unpaid basket is not something to do. It is configuration
    | rather than a constant so that change is a value and not a deploy of new
    | code.
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
    | assumes delivery. Past that they need a dispute, and there are none.
    |
    */

    'completion_extension_days' => 7,

    'max_completion_extensions' => 2,

];
