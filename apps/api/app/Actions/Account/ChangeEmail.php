<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use App\Notifications\Account\EmailAddressChanged;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Moves an account to a new email address, which then has to be confirmed.
 *
 * **The change is immediate, and the address unconfirmed.** Everything that
 * needs a confirmed address - checking out, applying to sell - waits until the
 * link sent to it is followed, exactly as for a new account.
 *
 * The alternative is keeping the old address until the new one is confirmed.
 * It protects against a typo, and it is a second column, a second signed link
 * and a second route to follow it to. The session that made the change stays
 * signed in whatever was typed, so a typo is corrected from the same page
 * (ADR 0034).
 */
final class ChangeEmail
{
    /**
     * @throws ValidationException when another account takes the address first
     */
    public function handle(User $user, string $email): User
    {
        $previous = $user->email;

        try {
            $user->forceFill([
                'email' => $email,
                'email_verified_at' => null,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // The request checked that the address was free. Another account
            // took it between that check and this write, and the unique index
            // said so.
            throw ValidationException::withMessages([
                'email' => 'The email has already been taken.',
            ]);
        }

        $user->sendEmailVerificationNotification();

        // The address being left is told. Once the change is made it is the
        // only address the owner still reads, so it is the only place somebody
        // who did not make the change can find out (ADR 0035).
        Notification::route('mail', $previous)->notify(new EmailAddressChanged($email));

        return $user;
    }
}
