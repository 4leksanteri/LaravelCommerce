<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Laravel 11 removed this from the generated base controller, on the
     * grounds that not every application authorizes anything. This one does:
     * `$this->authorize()` throws AuthorizationException, which the handler
     * renders as 403.
     *
     * Policies are registered by discovery - App\Models\Seller resolves to
     * App\Policies\SellerPolicy by name, with nothing to register by hand.
     * A policy that is not being applied is almost always a policy whose name
     * does not match its model.
     */
    use AuthorizesRequests;
}
