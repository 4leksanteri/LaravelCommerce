<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where product images live
    |--------------------------------------------------------------------------
    |
    | Stored per image row as well as here, because a marketplace that moves to
    | object storage still has to serve everything uploaded before the move.
    | This decides where *new* images go; old rows remember their own.
    |
    */

    'disk' => env('PRODUCT_IMAGE_DISK', 'products'),

    /*
    |--------------------------------------------------------------------------
    | What is accepted, and what is stored
    |--------------------------------------------------------------------------
    |
    | Anything reasonable in, exactly one thing out. Every upload is decoded,
    | re-oriented, downscaled and re-encoded as WebP, and the original is never
    | kept (ADR 0016).
    |
    | `max_edge` is a cap on the longest side rather than a set of sizes. Next's
    | image optimiser derives the responsive set from one source, so generating
    | our own would be the same work done twice - 1600 is comfortably above
    | anything it will ask for.
    |
    */

    'accepted_mimes' => ['jpeg', 'jpg', 'png', 'webp'],

    // Two megabytes, in kilobytes, which is the unit Laravel's `max` rule uses.
    'max_upload_kilobytes' => 2048,

    'max_edge' => 1600,

    // WebP is perceptually good well below where JPEG starts to look poor.
    'quality' => 82,

    /*
    |--------------------------------------------------------------------------
    | How many a listing may have
    |--------------------------------------------------------------------------
    */

    'max_per_product' => 8,

    /*
    |--------------------------------------------------------------------------
    | How long a link to a private image lasts
    |--------------------------------------------------------------------------
    |
    | Images of listings that are not on sale are served only against a signed
    | URL, and the signature expires. A leaked link to somebody's draft stops
    | working within the hour rather than never (ADR 0016).
    |
    | Long enough that a seller can leave their catalogue open on a screen;
    | short enough that a URL in a log or a browser history is not a key.
    |
    */

    'signed_url_minutes' => 60,

];
