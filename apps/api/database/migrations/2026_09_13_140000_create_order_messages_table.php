<?php

declare(strict_types=1);

use App\Enums\OrderParty;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the two sides of an order said to each other.
 *
 * **There is no conversations table, because the order is the conversation.**
 * A thread here is exactly one order's worth: it has two parties, both already
 * recorded on `orders`, and it begins the moment somebody writes. A table whose
 * every row would stand in a one-to-one relationship with an order is a column
 * on that order, and a join nobody needs.
 *
 * **The sender is which side, not which account.** `orders` already names the
 * buyer and the shop, so storing a `user_id` here would be a second copy of a
 * fact the order holds - and the copy that could disagree with it. It is the
 * same decision `cancelled_by` and `completed_by` took, and it reads the same
 * way: who, of the two parties, did this.
 *
 * **`read_at` belongs to the recipient.** It is set when the other side reads
 * the message, which is what an unread count is counted from. A message is
 * never unread to the person who wrote it, and the queries say so by asking for
 * messages this caller did not send.
 *
 * Nothing here is deleted. A conversation about an order is the record of what
 * was agreed outside the status field, and it is what a dispute would eventually
 * be argued from - so messages cascade with their order and by no other route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_messages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Which side wrote it. Twenty characters for the same reason
            // `cancelled_by` has them: the values are words, not codes.
            $table->string('sender', 20);

            $table->text('body');

            // When the other side read it. Null is unread, which is what the
            // counts on both order resources are built from.
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // How a conversation is read: one order's messages, oldest first,
            // which is the order they were said in.
            $table->index(['order_id', 'id']);
        });

        DB::statement(sprintf(
            "ALTER TABLE order_messages ADD CONSTRAINT order_messages_sender_check CHECK (sender IN ('%s'))",
            implode("', '", array_column(OrderParty::cases(), 'value')),
        ));

        // A message says something. An empty string is not a message, and it
        // would render as somebody having sent a blank line - the same rule
        // `reviews_body_not_blank` states for a review's words.
        DB::statement(
            'ALTER TABLE order_messages ADD CONSTRAINT order_messages_body_not_blank
             CHECK (length(btrim(body)) > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('order_messages');
    }
};
