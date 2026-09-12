<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;

/**
 * Stripe, as far as the SDK can tell.
 *
 * Installed as the SDK's HTTP client, so everything above the network is the
 * real code: the StripeClient, its services, the objects it builds from a
 * response and the exceptions it throws for a refusal. What is replaced is the
 * wire. Each request is recorded, and answered from responses the test queued.
 *
 * That is the seam worth faking. A fake of some gateway class of our own would
 * prove we call our own methods; this asserts on the parameters that actually
 * leave for Stripe, encoded the way Stripe receives them - which is why a
 * boolean reads back as the string 'true'.
 */
final class FakeStripe implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<array-key, mixed>, headers: list<string>}> */
    private array $sent = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $queued = [];

    /**
     * @param  array<string, mixed>  $body
     */
    public function respond(string $method, string $path, array $body, int $status = 200): self
    {
        $this->queued[strtoupper($method).' '.$path][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /**
     * Stripe refusing a request the way it does: a 400 naming the parameter.
     */
    public function refuse(string $method, string $path, string $parameter, string $message): self
    {
        return $this->respond($method, $path, [
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'parameter_invalid',
                'param' => $parameter,
                'message' => $message,
            ],
        ], 400);
    }

    /**
     * @param  string  $method
     * @param  string  $absUrl
     * @param  list<string>  $headers
     * @param  array<array-key, mixed>  $params
     * @param  bool  $hasFile
     * @param  string  $apiMode
     * @param  int|null  $maxNetworkRetries
     * @return array{0: string, 1: int, 2: CaseInsensitiveArray}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $method = strtoupper($method);
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $key = "{$method} {$path}";

        $this->sent[] = ['method' => $method, 'path' => $path, 'params' => $params, 'headers' => $headers];

        $queue = $this->queued[$key] ?? [];
        $response = array_shift($queue);
        $this->queued[$key] = $queue;

        if ($response === null) {
            Assert::fail("Stripe was sent {$key}, and the test set up no answer for it.");
        }

        return [
            json_encode($response['body'], JSON_THROW_ON_ERROR),
            $response['status'],
            new CaseInsensitiveArray(['request-id' => 'req_fake']),
        ];
    }

    /**
     * The parameters of the latest request to that endpoint.
     *
     * @return array<array-key, mixed>
     */
    public function sentTo(string $method, string $path): array
    {
        return $this->latest($method, $path)['params'];
    }

    /**
     * @return list<string>
     */
    public function headersSentTo(string $method, string $path): array
    {
        return $this->latest($method, $path)['headers'];
    }

    public function timesSentTo(string $method, string $path): int
    {
        return count(array_filter(
            $this->sent,
            static fn (array $request): bool => $request['method'] === strtoupper($method) && $request['path'] === $path,
        ));
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, 'Nothing should have reached Stripe.');
    }

    /**
     * A PaymentIntent as Stripe describes one, at whatever point it has
     * reached (ADR 0040).
     *
     * @param  array<string, mixed>  $overrides  replaces top-level keys
     * @return array<string, mixed>
     */
    public static function paymentIntent(
        string $id = 'pi_1Order',
        string $status = 'requires_payment_method',
        array $overrides = [],
    ): array {
        return array_replace([
            'id' => $id,
            'object' => 'payment_intent',
            'status' => $status,
            'amount' => 2499,
            'currency' => 'eur',
            'client_secret' => $id.'_secret_fake',
            'payment_method' => null,
            'last_payment_error' => null,
        ], $overrides);
    }

    /**
     * The same, paid, with the card Stripe kept against the customer.
     *
     * @return array<string, mixed>
     */
    public static function paidIntent(string $id = 'pi_1Order', string $paymentMethod = 'pm_1Card'): array
    {
        return self::paymentIntent($id, 'succeeded', ['payment_method' => $paymentMethod]);
    }

    /**
     * A card Stripe refused. The status is the one an untouched intent has,
     * and the error is the only thing that says otherwise.
     *
     * @return array<string, mixed>
     */
    public static function refusedIntent(string $id = 'pi_1Order', string $message = 'Your card was declined.'): array
    {
        return self::paymentIntent($id, 'requires_payment_method', [
            'last_payment_error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'message' => $message,
            ],
        ]);
    }

    /**
     * A buyer's customer, which is all Stripe needs to keep a card against.
     *
     * @return array<string, mixed>
     */
    public static function customer(string $id = 'cus_1Buyer'): array
    {
        return ['id' => $id, 'object' => 'customer'];
    }

    /**
     * An individual's account in Finland as Stripe describes one straight after
     * it is opened: everything about the person is due.
     *
     * @param  array<string, mixed>  $requirements  replaces keys of `requirements`
     * @param  array<string, mixed>  $overrides  replaces top-level keys
     * @return array<string, mixed>
     */
    public static function account(string $id = 'acct_1TestShop', array $requirements = [], array $overrides = []): array
    {
        $due = [
            'external_account',
            'individual.address.city',
            'individual.address.line1',
            'individual.address.postal_code',
            'individual.dob.day',
            'individual.dob.month',
            'individual.dob.year',
            'individual.email',
            'individual.first_name',
            'individual.last_name',
            'individual.phone',
        ];

        return [
            'id' => $id,
            'object' => 'account',
            'business_type' => 'individual',
            'country' => 'FI',
            'capabilities' => ['transfers' => 'inactive'],
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
            'requirements' => [
                'alternatives' => [],
                'current_deadline' => null,
                'currently_due' => $due,
                'disabled_reason' => 'requirements.past_due',
                'errors' => [],
                'eventually_due' => $due,
                'past_due' => $due,
                'pending_verification' => [],
                ...$requirements,
            ],
            'external_accounts' => self::bankAccounts($id, []),
            ...$overrides,
        ];
    }

    /**
     * The same account with everything sent and Stripe checking it.
     *
     * @return array<string, mixed>
     */
    public static function accountInReview(string $id = 'acct_1TestShop', string $last4 = '0785'): array
    {
        return self::account($id, requirements: [
            'currently_due' => [],
            'eventually_due' => [],
            'past_due' => [],
            'pending_verification' => ['individual.verification.document'],
            'disabled_reason' => 'requirements.pending_verification',
        ], overrides: [
            'capabilities' => ['transfers' => 'pending'],
            'external_accounts' => self::bankAccounts($id, [$last4]),
        ]);
    }

    /**
     * The same account verified: it takes transfers and pays them out.
     *
     * @return array<string, mixed>
     */
    public static function accountActive(string $id = 'acct_1TestShop', string $last4 = '0785'): array
    {
        return self::account($id, requirements: [
            'currently_due' => [],
            'eventually_due' => [],
            'past_due' => [],
            'disabled_reason' => null,
        ], overrides: [
            'capabilities' => ['transfers' => 'active'],
            'payouts_enabled' => true,
            'details_submitted' => true,
            'external_accounts' => self::bankAccounts($id, [$last4]),
        ]);
    }

    /**
     * @param  list<string>  $lastFours
     * @return array<string, mixed>
     */
    private static function bankAccounts(string $accountId, array $lastFours): array
    {
        return [
            'object' => 'list',
            'data' => array_map(static fn (string $last4): array => [
                'id' => 'ba_'.$last4,
                'object' => 'bank_account',
                'account' => $accountId,
                'country' => 'FI',
                'currency' => 'eur',
                'default_for_currency' => true,
                'last4' => $last4,
            ], $lastFours),
            'has_more' => false,
            'url' => "/v1/accounts/{$accountId}/external_accounts",
        ];
    }

    /**
     * @return array{method: string, path: string, params: array<array-key, mixed>, headers: list<string>}
     */
    private function latest(string $method, string $path): array
    {
        foreach (array_reverse($this->sent) as $request) {
            if ($request['method'] === strtoupper($method) && $request['path'] === $path) {
                return $request;
            }
        }

        Assert::fail("Nothing was sent to {$method} {$path}.");
    }
}
