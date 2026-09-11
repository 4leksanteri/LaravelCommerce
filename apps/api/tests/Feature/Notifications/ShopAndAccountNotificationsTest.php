<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Seller;
use App\Models\User;
use App\Notifications\Account\EmailAddressChanged;
use App\Notifications\Account\PasswordChanged;
use App\Notifications\Sellers\ShopApproved;
use App\Notifications\Sellers\ShopRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The review decision, told to the shop; and the two account changes a person
 * should hear about if they did not make them (ADR 0033, ADR 0034, ADR 0035).
 */
final class ShopAndAccountNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_approving_a_shop_tells_it(): void
    {
        $shop = Seller::factory()->create();

        $this->actingAs(User::factory()->staff()->create())
            ->fromFrontend()
            ->postJson("/api/v1/admin/sellers/{$shop->id}/approval")
            ->assertOk();

        Notification::assertSentTo($shop, ShopApproved::class);
    }

    /** The reason is what the applicant needs to apply again. */
    public function test_rejecting_a_shop_tells_it_why(): void
    {
        $shop = Seller::factory()->create();

        $this->actingAs(User::factory()->staff()->create())
            ->fromFrontend()
            ->postJson("/api/v1/admin/sellers/{$shop->id}/rejection", [
                'reason' => 'The photographs are stock images.',
            ])
            ->assertOk();

        Notification::assertSentTo(
            $shop,
            ShopRejected::class,
            static fn (ShopRejected $notification): bool => in_array(
                'The reason given: The photographs are stock images.',
                $notification->toMail($shop)->introLines,
                true,
            ),
        );
    }

    /**
     * Told at the address being left, because after the change that is the
     * only one its owner still reads. The new address is shown mostly hidden.
     */
    public function test_changing_the_email_address_tells_the_address_being_left(): void
    {
        $user = User::factory()->create(['email' => 'aino@example.test']);

        $this->actingAs($user)
            ->fromFrontend()
            ->putJson('/api/v1/account/email', [
                'email' => 'aino.v@example.test',
                'current_password' => 'password',
            ])
            ->assertOk();

        Notification::assertSentOnDemand(
            EmailAddressChanged::class,
            function (EmailAddressChanged $notification, array $channels, AnonymousNotifiable $notifiable): bool {
                $lines = implode(' ', $notification->toMail($notifiable)->introLines);

                // "aino.v": the first letter, and a star for each of the other five.
                $this->assertStringContainsString('a*****@example.test', $lines);
                $this->assertStringNotContainsString('aino.v@', $lines);

                return $notifiable->routes['mail'] === 'aino@example.test';
            },
        );
    }

    public function test_changing_the_password_tells_the_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->putJson('/api/v1/account/password', [
                'current_password' => 'password',
                'password' => 'a much longer passphrase',
                'password_confirmation' => 'a much longer passphrase',
            ])
            ->assertNoContent();

        Notification::assertSentTo(
            $user,
            PasswordChanged::class,
            fn (PasswordChanged $notification): bool => $notification->toMail($user)->actionUrl
                === 'http://localhost:3000/forgot-password',
        );
    }
}
