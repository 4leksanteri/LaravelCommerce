<?php

declare(strict_types=1);

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\AddressController;
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
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\Orders\CheckoutController;
use App\Http\Controllers\Orders\CheckoutPaymentController;
use App\Http\Controllers\Orders\OrderController;
use App\Http\Controllers\PublicProductController;
use App\Http\Controllers\PublicShopController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Sellers\PayoutAccountController;
use App\Http\Controllers\Sellers\ProductController;
use App\Http\Controllers\Sellers\ProductImageController;
use App\Http\Controllers\Sellers\ProductPublicationController;
use App\Http\Controllers\Sellers\ProductVariantController;
use App\Http\Controllers\Sellers\SellerOrderController;
use App\Http\Controllers\Sellers\ShopApplicationController;
use App\Http\Controllers\Sellers\ShopController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
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
| Browsing
|--------------------------------------------------------------------------
|
| Everything a shopper can reach without signing in.
|
*/

/*
| A product photograph.
|
| Public, and keyed by something unguessable rather than by an id: an image on
| a published listing is public by definition, and one on a draft is reachable
| only by whoever already has its key. That is the model a public bucket URL
| uses, which is what these become when images move to object storage.
|
| It lives here rather than behind `filesystems.local.serve`, which would put a
| file server on `/storage/{path}` - outside api/v1, where nothing proxies.
*/
Route::get('/images/{image}', ImageController::class)->name('images.show');

/*
| Browsing by what things are.
|
| The first public reads that do not need a shop slug the caller already has -
| everything else public is scoped to one shop, which is no use to somebody
| arriving at the front door.
|
| There is nothing here that writes a category. Staff own the list and there is
| no admin panel yet; they come from `CategorySeeder` (ADR 0017).
*/
/*
| Looking for something across the whole marketplace.
|
| The one public read that needs neither a shop slug nor a category - somebody
| who knows the model of the camera they want has neither. `q` is optional, so
| this doubles as "everything, newest first" (ADR 0020).
*/
Route::get('/search', SearchController::class)->name('search');

Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');

Route::get('/categories/{category}/products', [CategoryController::class, 'products'])
    ->name('categories.products');

/*
| A shop, and its storefront.
|
| A shop is here only once it has been approved, which is what approval means -
| the controller looks it up through the `public` scope rather than fetching it
| and checking afterwards.
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
| is - see the checkout route below.
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
| Addresses
|--------------------------------------------------------------------------
|
| The signed-in shopper's own address book. Every lookup starts from
| `$user->addresses()`, so there is no id here that could name somebody else's -
| and no route model binding, which would resolve globally (ADR 0021).
|
| No `verified`: saving an address is preparation, and the check belongs at
| checkout, which has it.
|
*/
Route::prefix('addresses')->name('addresses.')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [AddressController::class, 'index'])->name('index');

    Route::middleware('stateful')->group(function (): void {
        Route::post('/', [AddressController::class, 'store'])->name('store');

        Route::patch('/{address}', [AddressController::class, 'update'])
            ->whereNumber('address')
            ->name('update');

        Route::delete('/{address}', [AddressController::class, 'destroy'])
            ->whereNumber('address')
            ->name('destroy');
    });
});

/*
|--------------------------------------------------------------------------
| The account
|--------------------------------------------------------------------------
|
| The signed-in person's own name, email address and password (ADR 0034). No
| id anywhere: the account is the session's.
|
| The address and the password each need the current password, and share a
| limiter keyed by account, because a session somebody walked away from is a
| place to guess one from. Five a minute is plenty for a person who mistyped.
|
*/
Route::prefix('account')->name('account.')->middleware(['auth:sanctum', 'stateful'])->group(function (): void {
    Route::patch('/', [AccountController::class, 'update'])->name('update');

    Route::middleware('throttle:account-credentials')->group(function (): void {
        Route::put('/email', [AccountController::class, 'email'])->name('email');
        Route::put('/password', [AccountController::class, 'password'])->name('password');
    });
});

/*
|--------------------------------------------------------------------------
| Checkout and orders
|--------------------------------------------------------------------------
|
| Checkout's body carries one thing: which of the buyer's own addresses this
| goes to. The claim that mattered is unchanged - nothing a client sends
| contributes a figure to what somebody is charged. The cart, the prices and the
| totals are still read from the server under lock (ADR 0021).
|
| `verified` is here and not on the cart, which is the promise the cart's own
| comment makes. Filling a basket is browsing; buying something is when an
| address nobody has confirmed becomes a problem, because the confirmation,
| the receipt and everything about a dispute go to it.
|
| Orders are addressed by `reference` rather than by id. A sequential number
| in a URL publishes how many orders the marketplace has taken, and it is the
| string a buyer has in front of them anyway.
|
*/
Route::post('/checkout', CheckoutController::class)
    ->middleware(['auth:sanctum', 'verified', 'stateful'])
    ->name('checkout');

/*
|--------------------------------------------------------------------------
| Paying for a basket
|--------------------------------------------------------------------------
|
| Addressed by the checkout's reference rather than an order's, because one
| card pays for all of it: a basket spanning three shops is three orders, three
| currencies and three PaymentIntents (ADR 0015), and asking somebody to pay
| three times is what this exists to avoid.
|
| Reading creates any intent that is missing, so a checkout whose orders were
| written before Stripe could be reached is payable rather than stuck. Paying
| is throttled, because every attempt is a call Stripe counts against the
| platform.
|
*/
Route::prefix('checkouts/{reference}')
    ->name('checkouts.')
    ->middleware(['auth:sanctum', 'verified'])
    ->group(function (): void {
        Route::get('/payment', [CheckoutPaymentController::class, 'show'])
            ->whereAlphaNumeric('reference')
            ->name('payment.show');

        Route::post('/payment', [CheckoutPaymentController::class, 'pay'])
            ->middleware(['stateful', 'throttle:checkout-payment'])
            ->whereAlphaNumeric('reference')
            ->name('payment.pay');
    });

Route::prefix('orders')->name('orders.')->middleware('auth:sanctum')->group(function (): void {
    Route::get('/', [OrderController::class, 'index'])->name('index');

    Route::get('/{reference}', [OrderController::class, 'show'])
        ->whereAlphaNumeric('reference')
        ->name('show');

    /*
    | The two things a buyer can do to their own order.
    |
    | Each is a POST to the thing being recorded rather than a PATCH of
    | `status`, for the reason product publication is: a client that can set a
    | status field can set it to anything, and these are decisions rather than
    | values.
    |
    | There is no completion counterpart on the seller's routes below, and that
    | absence is the rule - confirming receipt is what will release a payout.
    */
    Route::middleware('stateful')->group(function (): void {
        Route::post('/{reference}/cancellation', [OrderController::class, 'cancel'])
            ->whereAlphaNumeric('reference')
            ->name('cancel');

        Route::post('/{reference}/completion', [OrderController::class, 'complete'])
            ->whereAlphaNumeric('reference')
            ->name('complete');

        // "It has not arrived yet." Pushes back the date the order would
        // otherwise complete on its own, a capped number of times. Not a
        // dispute - the buyer is not claiming anything went wrong.
        Route::post('/{reference}/completion-extension', [OrderController::class, 'extendCompletion'])
            ->whereAlphaNumeric('reference')
            ->name('extend-completion');
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
    /*
    | What has been bought from this shop.
    |
    | `seller` on the whole group, and every query inside starts from
    | `$seller->orders()` - so another shop's orders are not merely refused,
    | they are never in the query. A reference naming one answers 404.
    |
    | Accept, ship and cancel. Deliberately no completion: that is the buyer's,
    | because it is what will release a payout (ADR 0012).
    */
    Route::prefix('orders')->name('orders.')->middleware('seller')->group(function (): void {
        Route::get('/', [SellerOrderController::class, 'index'])->name('index');

        Route::get('/{reference}', [SellerOrderController::class, 'show'])
            ->whereAlphaNumeric('reference')
            ->name('show');

        Route::middleware('stateful')->group(function (): void {
            Route::post('/{reference}/acceptance', [SellerOrderController::class, 'accept'])
                ->whereAlphaNumeric('reference')
                ->name('accept');

            Route::post('/{reference}/shipment', [SellerOrderController::class, 'ship'])
                ->whereAlphaNumeric('reference')
                ->name('ship');

            Route::post('/{reference}/cancellation', [SellerOrderController::class, 'cancel'])
                ->whereAlphaNumeric('reference')
                ->name('cancel');
        });
    });

    /*
    | How the shop gets paid (ADR 0031). A singleton again - one payout account
    | per shop - and `seller` on all of it.
    |
    | Reading is the stored copy of what Stripe said and never calls Stripe.
    | Every write does, which is what `payout-account` limits: Stripe's rate
    | limit belongs to the platform, and one seller retrying in a loop should
    | run out of attempts before the marketplace does.
    */
    Route::prefix('payout-account')->name('payout-account.')->middleware('seller')->group(function (): void {
        Route::get('/', [PayoutAccountController::class, 'show'])->name('show');

        Route::middleware(['stateful', 'throttle:payout-account'])->group(function (): void {
            Route::post('/', [PayoutAccountController::class, 'store'])->name('open');
            Route::patch('/', [PayoutAccountController::class, 'update'])->name('update');

            // Multipart, like a product photograph, and passed on to Stripe
            // rather than kept.
            Route::post('/identity-document', [PayoutAccountController::class, 'identityDocument'])
                ->name('identity-document');
        });
    });

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
            /*
            | Photographs. Multipart rather than JSON, and `scopeBindings()`
            | for the same reason the variant routes have it.
            */
            Route::post('/{product}/images', [ProductImageController::class, 'store'])
                ->name('images.store');
            Route::patch('/{product}/images/{image}', [ProductImageController::class, 'update'])
                ->scopeBindings()
                ->name('images.update');
            Route::delete('/{product}/images/{image}', [ProductImageController::class, 'destroy'])
                ->scopeBindings()
                ->name('images.destroy');

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

/*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
|
| Called by Stripe rather than by anybody using the site, so there is no
| session and nothing here would give one: Stripe sends no Origin or Referer,
| Sanctum never engages, and `stateful` would refuse it (ADR 0015).
|
| The signature is the credential. It is checked against the raw body, which
| the proxy streams through untouched - a body parsed and re-serialised on the
| way would no longer be the bytes Stripe signed.
|
*/
Route::post('/webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');
