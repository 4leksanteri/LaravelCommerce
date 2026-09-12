<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * An intent that exists and has not been paid, because that is where every
     * payment starts. The states below move it on, and each keeps the table's
     * own check constraints satisfied - a succeeded payment has its date, and
     * only a failed one has a reason.
     *
     * @return array<model-property<Payment>, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'stripe_payment_intent_id' => 'pi_'.Str::lower(Str::random(24)),
            'status' => PaymentStatus::Pending,
            'amount_minor' => 2499,
            'currency' => Currency::EUR,
            'stripe_payment_method_id' => null,
            'failure_reason' => null,
            'paid_at' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Succeeded,
            'stripe_payment_method_id' => 'pm_'.Str::lower(Str::random(24)),
            'failure_reason' => null,
            'paid_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Your card was declined.'): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Failed,
            'failure_reason' => $reason,
            'paid_at' => null,
        ]);
    }

    /** Paid, and the shop's share sent on, less the fee it was taken with. */
    public function transferred(int $feeMinor = 125): static
    {
        return $this->paid()->state(fn (): array => [
            'platform_fee_minor' => $feeMinor,
            'stripe_transfer_id' => 'tr_'.Str::lower(Str::random(24)),
            'transferred_at' => now(),
        ]);
    }

    /** Paid, and given back. The status stays succeeded: the charge did. */
    public function refunded(): static
    {
        return $this->paid()->state(fn (): array => [
            'stripe_refund_id' => 're_'.Str::lower(Str::random(24)),
            'refunded_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Cancelled,
            'failure_reason' => null,
            'paid_at' => null,
        ]);
    }

    /**
     * For an order whose total is what matters: the payment copies the order's
     * own amount and currency, which is what the real thing does.
     */
    public function forOrder(Order $order): static
    {
        return $this->state(fn (): array => [
            'order_id' => $order->id,
            'amount_minor' => $order->total_minor,
            'currency' => $order->currency,
        ]);
    }
}
