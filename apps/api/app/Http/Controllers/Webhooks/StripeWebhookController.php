<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\Payouts\HandleStripeEvent;
use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * Where Stripe tells this application that something happened.
 *
 * The one endpoint called by something other than a person using the site,
 * and so the one with no session. Stripe sends no Origin and no Referer, so
 * Sanctum never starts one and CSRF never applies (ADR 0015).
 *
 * **The signature is the credential.** It is an HMAC of the raw body under a
 * secret only Stripe and this application hold, checked against the body
 * exactly as it arrived - which is why the proxy streams the body through
 * rather than parsing it. A request without a valid one is refused and nothing
 * else happens.
 *
 * A verified event is acted on and answered 204. Anything that fails after
 * that is a 500, which tells Stripe to send it again, and HandleStripeEvent
 * makes sure the second delivery is not a duplicate.
 */
final class StripeWebhookController extends Controller
{
    /**
     * Receives a Stripe event.
     *
     * Called by Stripe, never by the web application. Without Stripe's
     * signature every request is refused.
     */
    #[DocumentedResponse(status: 400, description: 'The signature is missing or does not match, or the body is not an event.', type: 'array{message: string}')]
    public function __invoke(Request $request, HandleStripeEvent $handle): Response|JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('STRIPE_WEBHOOK_SECRET is not set, so no webhook can be verified. See .env.example.');
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->headers->get('Stripe-Signature') ?? '',
                $secret,
            );
        } catch (SignatureVerificationException) {
            return new JsonResponse(['message' => 'The signature is missing or does not match.'], 400);
        } catch (UnexpectedValueException) {
            return new JsonResponse(['message' => 'The body is not a Stripe event.'], 400);
        }

        $handle->handle($event);

        return response()->noContent();
    }
}
