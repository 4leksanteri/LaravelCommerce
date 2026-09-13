<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Enums\OrderStatus;
use App\Exceptions\ReviewNotAllowedException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Somebody says what they thought of something they bought (ADR 0047).
 *
 * **Only a completed order earns one**, and that is the whole rule. Completion
 * is the buyer confirming the parcel arrived, and it is what releases the money
 * to the shop (ADR 0014) - so it is the strongest statement this domain has
 * that a person actually received the thing they are about to talk about. Paid
 * is not enough: a card is charged before anything is posted.
 *
 * That makes "verified purchase" structural rather than a badge. There is no
 * way to write a review here without an order behind it, so there is no
 * unverified kind to distinguish it from.
 *
 * **The order is recorded** on the review as the proof. Re-deriving it later
 * would mean a seller could make a standing review look unearned by deleting a
 * variant, because an order line's link to the catalogue is nullable (ADR 0011).
 */
final class LeaveReview
{
    /**
     * @throws ReviewNotAllowedException when nothing they have bought entitles
     *                                   them, or they have already said their
     *                                   piece
     */
    public function handle(User $buyer, Product $product, int $rating, ?string $body): Review
    {
        $order = $this->entitlingOrder($buyer, $product);

        if (! $order instanceof Order) {
            throw ReviewNotAllowedException::notBought();
        }

        $review = new Review;

        $review->forceFill([
            'product_id' => $product->id,
            'user_id' => $buyer->id,
            'order_id' => $order->id,
            'rating' => $rating,
            'body' => $body,
        ]);

        try {
            $review->save();
        } catch (UniqueConstraintViolationException) {
            /*
             * The index is the guard rather than a read before the write. Two
             * requests arriving together both find nothing and both insert, and
             * the second is refused by the database - which is the same answer
             * a check would have given, arrived at by the only party that can
             * see both.
             */
            throw ReviewNotAllowedException::alreadyReviewed();
        }

        return $review;
    }

    /**
     * The oldest completed order of theirs that contained this listing.
     *
     * **Subqueries rather than `whereHas` closures**, for the reason
     * `SellerTransferController` gives: inside `whereHas` the closure is handed
     * a `Builder<Model>`, which the analyser cannot check a column name
     * against. Starting from each model in turn gives every condition something
     * real to be checked against.
     *
     * A line whose variant the seller has since deleted cannot entitle
     * anything, because the link is what says which listing was bought. Rare,
     * and the honest failure: the alternative is guessing from a product name
     * somebody typed.
     */
    private function entitlingOrder(User $buyer, Product $product): ?Order
    {
        $variants = ProductVariant::query()->where('product_id', $product->id)->select('id');
        $orders = OrderItem::query()->whereIn('product_variant_id', $variants)->select('order_id');

        return Order::query()
            ->where('user_id', $buyer->id)
            ->where('status', OrderStatus::Completed)
            ->whereIn('id', $orders)
            ->oldest('id')
            ->first();
    }

    /** Whether this person could leave one, asked without writing anything. */
    public function isAllowedFor(User $buyer, Product $product): bool
    {
        if (Review::query()->where('user_id', $buyer->id)->where('product_id', $product->id)->exists()) {
            return false;
        }

        return $this->entitlingOrder($buyer, $product) instanceof Order;
    }
}
