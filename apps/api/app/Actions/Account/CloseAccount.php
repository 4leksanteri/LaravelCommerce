<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\OrderStatus;
use App\Exceptions\AccountNotCloseableException;
use App\Models\Order;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\Account\AccountClosed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Closes somebody's account: it stops working, and stops naming them
 * (ADR 0058).
 *
 * **It is not a delete, and it cannot be.** `orders.user_id` and
 * `orders.seller_id` are `restrictOnDelete`, so PostgreSQL refuses to remove
 * anybody who has ever bought or sold - which is ADR 0011's "a receipt has to
 * outlive the account that paid it", enforced rather than merely written down.
 *
 * It is not `SoftDeletes` either. That would add a global scope, and a global
 * scope would hide this row from every relation that points at it: an order's
 * buyer would resolve to null and a shop's receipt would stop saying who it
 * shipped to. The row stays readable and is stripped instead.
 *
 * ```text
 * gone     the name, the address, the password, the sessions, the address
 *          book, the basket, the Stripe customer
 * kept     orders and what they froze, reviews, reports, messages, disputes
 * ```
 *
 * **What is kept is kept for a reason, not for convenience.** An order is a
 * record of a transaction and the other party has it too. A review is a public
 * statement that ADR 0047 refused to let anybody erase - and letting an account
 * closure take one would be a back door straight through that decision, quietly
 * rewriting the rating of whichever shop it was about. A report is moderation's
 * record of a decision.
 *
 * **It does not claim to erase the person.** ADR 0021 freezes a name, a postal
 * address and a telephone number onto every order at checkout, and this does
 * not touch them: they are what the parcel was sent to, and the shop has the
 * same record. ADR 0058 says so plainly rather than overclaiming.
 */
final class CloseAccount
{
    /**
     * @throws AccountNotCloseableException when the marketplace still owes
     *                                      somebody something
     */
    public function handle(User $user): void
    {
        $this->refuseWhileAnythingIsUnfinished($user);

        // Captured before the write, because in a moment there will be no
        // address to send to. `ChangeEmail` orders it the same way, and for the
        // same reason.
        $address = $user->email;
        $name = $user->name;

        DB::transaction(function () use ($user): void {
            /*
             * Re-checked under a lock, for the reason `SuspendShop` gives: the
             * answer read a moment ago is a fact about the past, and an order
             * placed between the two reads would be stranded by a closure that
             * did not see it.
             */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->refuseWhileAnythingIsUnfinished($locked);

            $locked->forceFill([
                /*
                 * Not blanked. `users.email` is unique, so a second closed
                 * account would collide with the first - and the id makes each
                 * one unique without saying anything about who it was.
                 *
                 * `.invalid` is reserved by RFC 2606 precisely so that it can
                 * never be delivered to, which is what this address is for.
                 */
                'email' => "closed-{$locked->id}@deleted.invalid",
                'name' => 'Closed account',

                // Nobody can sign in, and no reset can be requested: the
                // address a link would go to does not exist.
                'password' => Str::random(64),
                'remember_token' => Str::random(60),
                'email_verified_at' => null,

                // The customer at Stripe belongs to a person. Forgetting the id
                // is this side's half; ADR 0058 says what is not done about the
                // other half.
                'stripe_customer_id' => null,

                'closed_at' => now(),
            ])->save();

            // The address book is not a transaction record: where somebody
            // lives is theirs, and every order kept its own frozen copy of
            // wherever its parcel went (ADR 0021).
            $locked->addresses()->delete();

            // A basket is a thing somebody was thinking about buying, which is
            // as personal as it is worthless once the account is closed.
            $locked->cart()->delete();

            // Every session, including the one closing the account: there is
            // nothing left to be signed in to. `sessions.user_id` carries no
            // foreign key, so nothing cascades and this is the only thing that
            // clears them (ADR 0034).
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $locked->id)
                ->delete();
        });

        // To the address that has just stopped being theirs, which is the only
        // place somebody who did not do this would find out that it happened
        // (ADR 0035). After the commit, like every other mail here.
        Notification::route('mail', $address)->notify(new AccountClosed($name));
    }

    /**
     * Everything that has to be finished first.
     *
     * Ordered from the most specific to the least, so somebody is told the
     * nearest thing standing in the way rather than the broadest. Each is asked
     * of **both sides**: a shop owner is a buyer too, and an account is one
     * account however many ways it trades.
     *
     * @throws AccountNotCloseableException
     */
    private function refuseWhileAnythingIsUnfinished(User $user): void
    {
        $shop = $user->seller;

        // Asked first because it is the one this action cannot resolve at all.
        // A shop with no orders is still a shop, and closing it needs a chapter
        // of its own - suspension records a member of staff, and there is none.
        if ($shop instanceof Seller && ! $shop->isSuspended()) {
            throw AccountNotCloseableException::aShopIsStillOpen();
        }

        $orders = $this->everyOrderTouching($user);

        if ((clone $orders)->whereHas('dispute', static fn ($dispute) => $dispute->whereNull('resolved_at'))->exists()) {
            throw AccountNotCloseableException::aDisputeIsOpen();
        }

        if ((clone $orders)->whereIn('status', $this->openStatuses())->exists()) {
            throw AccountNotCloseableException::ordersAreOpen();
        }

        /*
         * Money that is owed **back**, and only that.
         *
         * `isHeld()` is paid, not refunded and not transferred (ADR 0041), and
         * a completed order sits in that state whenever its shop has no active
         * payout account - `TransferToShop` returns early and a settlement run
         * collects it later. Refusing on that would hold a buyer who has
         * confirmed their parcel hostage to the platform's unfinished business
         * with a shop they have no stake in, which in a stack where no shop has
         * a payout account is every buyer.
         *
         * What is left once completed orders are excluded is the case worth
         * refusing for: an order called off whose refund has not arrived. The
         * open ones are already gone by the check above.
         *
         * Expressed as columns rather than through `isHeld()` because this asks
         * about many orders in one query, and that method answers about one
         * payment already in memory.
         */
        $owed = (clone $orders)
            ->where('status', '!=', OrderStatus::Completed)
            ->whereHas('payment', static fn ($payment) => $payment
                ->whereNotNull('paid_at')
                ->whereNull('refunded_at')
                ->whereNull('transferred_at'));

        if ($owed->exists()) {
            throw AccountNotCloseableException::aRefundIsOwed();
        }
    }

    /**
     * Orders this account is a party to, on either side.
     *
     * A shop owner who closes their account is walking away from what they sold
     * as well as what they bought, and both sides have somebody waiting at the
     * other end of them.
     *
     * @return Builder<Order>
     */
    private function everyOrderTouching(User $user): Builder
    {
        $shopId = $user->seller?->id;

        return Order::query()->where(static function ($query) use ($user, $shopId): void {
            $query->where('user_id', $user->id);

            if ($shopId !== null) {
                $query->orWhere('seller_id', $shopId);
            }
        });
    }

    /**
     * The statuses that still have somebody waiting.
     *
     * Read off `OrderStatus::isOpen()` rather than listed, so a sixth case
     * added to that enum is covered here without anybody remembering to come
     * back - the same reason `Currency` and `Carrier` are asked rather than
     * copied.
     *
     * @return list<string>
     */
    private function openStatuses(): array
    {
        $open = [];

        foreach (OrderStatus::cases() as $status) {
            if ($status->isOpen()) {
                $open[] = $status->value;
            }
        }

        return $open;
    }
}
