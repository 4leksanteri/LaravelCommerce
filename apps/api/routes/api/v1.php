<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\SellerReviewController;
use App\Http\Controllers\Auth\AuthenticatedUserController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResendVerificationEmailController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Cart\CartController;
use App\Http\Controllers\Cart\CartItemController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PublicProductController;
use App\Http\Controllers\PublicShopController;
use App\Http\Controllers\Sellers\ProductController;
use App\Http\Controllers\Sellers\ProductPublicationController;
use App\Http\Controllers\Sellers\ProductVariantController;
use App\Http\Controllers\Sellers\ShopApplicationController;
use App\Http\Controllers\Sellers\ShopController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Mounted under /api/v1 by bootstrap/app.php. This is the whole public
| surface of the application: the Next.js server proxies /api/** here and
| nothing else reaches this process from outside the Docker network.
|
| Routes are resource-oriented and use HTTP semantics properly - GET reads,
| POST creates, PATCH partially updates, DELETE removes. Business logic lives
| in services, not here and not in the controllers below.
|
*/

// Public. Says whether the application can serve a request, and nothing about
// who is asking. Deliberately says nothing about the database, the queue or
// any dependency: a probe that fails when a downstream is slow takes a healthy
// process out of rotation for someone else's problem.
Route::get('/health', HealthController::class)->name('health');

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Session authentication through Sanctum's stateful guard. Reasoning, and the
| traps, are in docs/architecture/0002-authentication.md.
|
| Three middleware appear repeatedly and each is load-bearing:
|
|   stateful   refuses a request that cannot hold a session, with a 400 that
|              says why rather than a 500 that does not
|   throttle   named limiters defined in AppServiceProvider, keyed by address
|              and IP together
|   signed     the emailed link is the credential; `relative` because the
|              signature must not depend on the host the proxy forwarded
|
| GET /auth/csrf-cookie is not listed here. Sanctum publishes it, under this
| same prefix, because config/sanctum.php sets one.
|
*/
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/register', RegisterController::class)
        ->middleware(['stateful', 'throttle:auth-register'])
        ->name('register');

    Route::post('/login', LoginController::class)
        ->middleware(['stateful', 'throttle:auth-login'])
        ->name('login');

    Route::post('/logout', LogoutController::class)
        ->middleware(['auth:sanctum', 'stateful'])
        ->name('logout');

    // Who the session cookie belongs to. The Next.js application calls this to
    // decide what to render; it is not, and must never be, an authorization
    // check. Every endpoint answers that question for itself.
    Route::get('/me', AuthenticatedUserController::class)
        ->middleware('auth:sanctum')
        ->name('me');

    Route::prefix('password')->name('password.')->group(function (): void {
        Route::post('/forgot', ForgotPasswordController::class)
            ->middleware('throttle:auth-password-forgot')
            ->name('forgot');

        // No session needed: the token from the email is the credential, and
        // somebody resetting a password is usually locked out of everything.
        Route::post('/reset', ResetPasswordController::class)
            ->middleware('throttle:auth-password-forgot')
            ->name('reset');
    });

    Route::prefix('email')->name('email.')->group(function (): void {
        // The name matters. AppServiceProvider signs this exact route name
        // when it builds the link, so renaming it here breaks every link
        // already sitting in somebody's inbox.
        Route::get('/verify/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['signed:relative', 'throttle:6,1'])
            ->name('verify');

        Route::post('/resend', ResendVerificationEmailController::class)
            ->middleware(['auth:sanctum', 'throttle:auth-email-resend'])
            ->name('resend');
    });
});

/*
|--------------------------------------------------------------------------
| Shops
|--------------------------------------------------------------------------
|
| Public. A shop is here only once it has been approved, which is what
| approval means - the controller looks it up through the `public` scope
| rather than fetching it and checking afterwards.
|
*/
Route::get('/shops/{slug}', PublicShopController::class)->name('shops.show');

// The storefront. Both the shop and the listing must pass their `public`
// scope, so an unapproved shop cannot show a single product however it has
// set the status.
Route::get('/shops/{shopSlug}/products', [PublicProductController::class, 'index'])
    ->name('shops.products.index');

Route::get('/shops/{shopSlug}/products/{productSlug}', [PublicProductController::class, 'show'])
    ->name('shops.products.show');

/*
|--------------------------------------------------------------------------
| The cart
|--------------------------------------------------------------------------
|
| The signed-in shopper's basket. A singleton - one cart per account
| (ADR 0010) - so there is no id in the path and nothing for a caller to
| substitute.
|
| `verified` is deliberately absent. Filling a basket is browsing; an address
| nobody has confirmed becomes a problem at checkout, which is where the check
| will go.
|
| Line ids do appear, and they are resolved through the caller's own cart
| rather than by route model binding. Implicit binding resolves globally, so
| `{item}` would otherwise be any line in the database - somebody else's
| included. There is a test that asks for another account's line and expects a
| 404.
|
*/
Route::prefix('cart')->name('cart.')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [CartController::class, 'show'])->name('show');

    Route::middleware('stateful')->group(function (): void {
        Route::delete('/', [CartController::class, 'destroy'])->name('empty');

        Route::post('/items', [CartItemController::class, 'store'])->name('items.store');

        Route::patch('/items/{item}', [CartItemController::class, 'update'])
            ->whereNumber('item')
            ->name('items.update');

        Route::delete('/items/{item}', [CartItemController::class, 'destroy'])
            ->whereNumber('item')
            ->name('items.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| Selling
|--------------------------------------------------------------------------
|
| The signed-in person's own shop. A singleton - one shop per account
| (ADR 0007) - so there is no id in any of these paths.
|
| `verified` on the application, and deliberately not on the rest. The whole
| review conversation happens by email, so applying with an address nobody has
| shown they can read is applying into a void. Reading and editing a shop that
| already exists does not need the check a second time.
|
*/
Route::prefix('seller')->name('seller.')->middleware('auth:sanctum')->group(function (): void {
    Route::post('/application', ShopApplicationController::class)
        ->middleware(['verified', 'stateful', 'throttle:seller-application'])
        ->name('apply');

    Route::get('/', [ShopController::class, 'show'])->name('show');

    // `seller` requires a shop and resolves it, so the controller has one
    // without looking it up and without a "you have no shop" branch. Every
    // future seller-only endpoint - products, orders, payouts - goes behind
    // the same alias.
    Route::patch('/', [ShopController::class, 'update'])
        ->middleware(['stateful', 'seller'])
        ->name('update');

    /*
    | The seller's catalogue. Everything here needs a shop, so `seller` is on
    | the whole group.
    |
    | Creating a draft deliberately does not need an approved shop: somebody
    | waiting on review can prepare their listings. Publishing does, and that
    | is PublishProduct's rule rather than a middleware, because it is a fact
    | about the shop rather than about the caller - a 409, not a 403.
    */
    Route::prefix('products')->name('products.')->middleware('seller')->group(function (): void {
        Route::get('/', [ProductController::class, 'index'])->name('index');
        Route::get('/{product}', [ProductController::class, 'show'])->name('show');

        Route::middleware('stateful')->group(function (): void {
            Route::post('/', [ProductController::class, 'store'])->name('store');
            Route::patch('/{product}', [ProductController::class, 'update'])->name('update');
            Route::delete('/{product}', [ProductController::class, 'destroy'])->name('destroy');

            // A publication is a thing that gets created and removed, not a
            // status field a client sets.
            Route::post('/{product}/publication', [ProductPublicationController::class, 'store'])
                ->name('publish');
            Route::delete('/{product}/publication', [ProductPublicationController::class, 'destroy'])
                ->name('unpublish');

            /*
            | scopeBindings() is load-bearing. Without it `{variant}` resolves
            | globally, and a seller could edit another shop's variant by
            | putting its id after their own product's path.
            */
            Route::post('/{product}/variants', [ProductVariantController::class, 'store'])
                ->name('variants.store');
            Route::patch('/{product}/variants/{variant}', [ProductVariantController::class, 'update'])
                ->scopeBindings()
                ->name('variants.update');
            Route::delete('/{product}/variants/{variant}', [ProductVariantController::class, 'destroy'])
                ->scopeBindings()
                ->name('variants.destroy');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Platform administration
|--------------------------------------------------------------------------
|
| Staff only, enforced by SellerPolicy rather than by this prefix. A route
| group is not an authorization boundary: it is a URL, and the day somebody
| moves a controller out of the group the check has to still be there.
|
*/
Route::prefix('admin')->name('admin.')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/sellers', [SellerReviewController::class, 'index'])->name('sellers.index');

    // Approving and rejecting are decisions being recorded, so each is a POST
    // to the thing being created rather than a PATCH that sets a status field
    // a client could set to anything.
    Route::post('/sellers/{seller}/approval', [SellerReviewController::class, 'approve'])
        ->middleware('stateful')
        ->name('sellers.approve');

    Route::post('/sellers/{seller}/rejection', [SellerReviewController::class, 'reject'])
        ->middleware('stateful')
        ->name('sellers.reject');
});
