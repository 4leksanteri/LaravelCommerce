<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Replaces an account's password, and ends every other session it has.
 *
 * A password is usually changed because somebody else might know it, and a
 * session they already hold would outlive the change. So every session this
 * account has is deleted except the one making the change, and the "remember
 * me" token is rotated, as a reset does, so a cookie on a lost device stops
 * working too.
 *
 * Sessions are rows in the `sessions` table (SESSION_DRIVER=database in both
 * compose files). Moving sessions to another store makes the delete below a
 * no-op, and this is the place that would have to follow them (ADR 0034).
 */
final class ChangePassword
{
    /**
     * @param  string|null  $keepSessionId  the session making the change, which stays signed in
     */
    public function handle(User $user, #[SensitiveParameter] string $password, ?string $keepSessionId): void
    {
        DB::transaction(function () use ($user, $password, $keepSessionId): void {
            $user->forceFill([
                // Hashed by the model's cast. Hashing here as well would store
                // a hash of a hash, and the new password would never work.
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when(
                    $keepSessionId !== null,
                    static fn ($sessions) => $sessions->where('id', '!=', $keepSessionId),
                )
                ->delete();
        });
    }
}
