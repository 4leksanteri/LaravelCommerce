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

];
