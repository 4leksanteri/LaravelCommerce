<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedUserController;
use App\Http\Controllers\HealthController;
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

Route::middleware('auth:sanctum')->group(function (): void {
    // Who the session cookie belongs to. The Next.js application calls this to
    // decide what to render; it is not, and must never be, an authorization
    // check. Every endpoint answers that question for itself.
    Route::get('/auth/me', AuthenticatedUserController::class)->name('auth.me');
});
