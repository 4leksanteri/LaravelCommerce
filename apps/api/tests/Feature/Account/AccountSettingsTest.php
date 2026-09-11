<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Changing your own name, address and password (ADR 0034).
 *
 * The factory's password is the string "password", which is what every
 * `current_password` below is.
 */
final class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_person_changes_their_name(): void
    {
        $user = User::factory()->create(['name' => 'Aino']);

        $this->actingAs($user)
            ->fromFrontend()
            ->patchJson('/api/v1/account', ['name' => 'Aino Virtanen'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Aino Virtanen');
    }

    /**
     * The name endpoint takes a name. An address or a role sent along with it
     * is not in its rules and changes nothing.
     */
    public function test_changing_the_name_changes_nothing_else(): void
    {
        $user = User::factory()->create(['email' => 'aino@example.test']);

        $this->actingAs($user)
            ->fromFrontend()
            ->patchJson('/api/v1/account', [
                'name' => 'Aino',
                'email' => 'someone-else@example.test',
                'role' => 'admin',
            ])
            ->assertOk();

        $user->refresh();
        $this->assertSame('aino@example.test', $user->email);
        $this->assertFalse($user->isPlatformStaff());
    }

    public function test_every_change_needs_somebody_signed_in(): void
    {
        $this->fromFrontend()->patchJson('/api/v1/account', ['name' => 'Aino'])->assertUnauthorized();
        $this->fromFrontend()->putJson('/api/v1/account/email', [])->assertUnauthorized();
        $this->fromFrontend()->putJson('/api/v1/account/password', [])->assertUnauthorized();
    }

    /**
     * A new address takes effect at once, unconfirmed, and a link to confirm
     * it goes to the new address - so what needs a confirmed address waits for
     * it, as for a new account.
     */
    public function test_a_new_address_has_to_be_confirmed_again(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'aino@example.test']);

        $this->actingAs($user)
            ->fromFrontend()
            ->putJson('/api/v1/account/email', [
                'email' => ' Aino.V@Example.test ',
                'current_password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'aino.v@example.test')
            ->assertJsonPath('data.email_verified_at', null)
            ->assertJsonPath('data.shop_application_blocker', 'unverified_email');

        Notification::assertSentTo(
            $user,
            VerifyEmail::class,
            static fn (VerifyEmail $notification, array $channels, User $notifiable): bool => $notifiable->email === 'aino.v@example.test',
        );
    }

    public function test_changing_the_address_needs_the_current_password(): void
    {
        $user = User::factory()->create(['email' => 'aino@example.test']);

        $this->actingAs($user)
            ->fromFrontend()
            ->putJson('/api/v1/account/email', [
                'email' => 'aino.v@example.test',
                'current_password' => 'not the password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password' => 'That is not your current password.']);

        $this->assertSame('aino@example.test', $user->refresh()->email);
    }

    public function test_an_address_another_account_holds_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->putJson('/api/v1/account/email', [
                'email' => 'taken@example.test',
                'current_password' => 'password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    /** Changing to the address already held would unconfirm it for nothing. */
    public function test_the_address_already_held_is_refused_rather_than_unconfirmed(): void
    {
        $user = User::factory()->create(['email' => 'aino@example.test']);

        $this->actingAs($user)
            ->fromFrontend()
            ->putJson('/api/v1/account/email', [
                'email' => 'aino@example.test',
                'current_password' => 'password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'That is already your email address.']);

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * A password is changed because somebody else might know it, so every
     * other session the account has ends - and the "remember me" token turns
     * over, as a reset does. Another account's sessions are not touched.
     */
    public function test_a_new_password_replaces_the_old_one_and_signs_out_everywhere_else(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $rememberToken = $user->remember_token;

        DB::table('sessions')->insert([
            $this->sessionRow('on-a-lost-phone', $user),
            $this->sessionRow('somebody-else', $other),
        ]);

        $this->actingAs($user)
            ->fromFrontend()
            ->putJson('/api/v1/account/password', [
                'current_password' => 'password',
                'password' => 'a much longer passphrase',
                'password_confirmation' => 'a much longer passphrase',
            ])
            ->assertNoContent();

        $user->refresh();
        $this->assertTrue(Hash::check('a much longer passphrase', $user->password));
        $this->assertNotSame($rememberToken, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'on-a-lost-phone']);
        $this->assertDatabaseHas('sessions', ['id' => 'somebody-else']);
    }

    public function test_changing_the_password_needs_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->putJson('/api/v1/account/password', [
                'current_password' => 'a guess',
                'password' => 'a much longer passphrase',
                'password_confirmation' => 'a much longer passphrase',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    /** The same rules as registration, from the same `Password::defaults()`. */
    public function test_a_new_password_is_held_to_registrations_rules(): void
    {
        $this->actingAs(User::factory()->create())
            ->fromFrontend()
            ->putJson('/api/v1/account/password', [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_the_new_password_has_to_be_a_different_one(): void
    {
        $user = User::factory()->create(['password' => 'the same long password']);

        $this->actingAs($user)
            ->fromFrontend()
            ->putJson('/api/v1/account/password', [
                'current_password' => 'the same long password',
                'password' => 'the same long password',
                'password_confirmation' => 'the same long password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'Choose a password other than the one you have now.']);
    }

    /**
     * A session left open somewhere is a place to guess the current password
     * from. Five tries a minute, per account, for both changes together.
     */
    public function test_guessing_the_current_password_is_limited(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->actingAs($user)
                ->fromFrontend()
                ->putJson('/api/v1/account/email', [
                    'email' => "guess-{$attempt}@example.test",
                    'current_password' => "guess {$attempt}",
                ])
                ->assertUnprocessable();
        }

        $this->actingAs($user)
            ->fromFrontend()
            ->putJson('/api/v1/account/password', [
                'current_password' => 'guess 6',
                'password' => 'a much longer passphrase',
                'password_confirmation' => 'a much longer passphrase',
            ])
            ->assertTooManyRequests();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionRow(string $id, User $user): array
    {
        return [
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '198.51.100.7',
            'user_agent' => 'Somewhere else',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ];
    }
}
