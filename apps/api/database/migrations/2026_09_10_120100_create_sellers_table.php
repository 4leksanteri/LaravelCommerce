<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Enums\SellerStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table): void {
            $table->id();

            // Unique: one shop per account (ADR 0007). The constraint is what
            // makes `$user->seller` a single row rather than a guess, and
            // relaxing it later means revisiting every query that assumed one.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('shop_name');

            // The public address of the shop. Derived from the name once, at
            // application, and never rewritten when the name changes - a slug
            // that moves breaks every link anybody saved or shared.
            $table->string('slug')->unique();

            $table->text('description')->nullable();

            // Where buyers reach the shop. Separate from the account's own
            // address on purpose: a shop is often run from a different mailbox
            // from the one somebody signs in with.
            $table->string('contact_email');

            // Chosen at application, fixed afterwards. Everything the shop
            // does is denominated in it, and amounts in different currencies
            // are never summed (ADR 0004).
            $table->char('currency', 3);

            $table->string('status', 20)->default(SellerStatus::Pending->value);

            $table->text('rejection_reason')->nullable();

            $table->timestamp('applied_at');
            $table->timestamp('reviewed_at')->nullable();

            // Who decided. Kept when that person's account goes, because the
            // decision still happened - nullOnDelete rather than cascade,
            // which would take the shop with the reviewer.
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The review queue reads this constantly and nothing else.
            $table->index('status');
        });

        // Invariants the application also enforces, stated here as well
        // because this is the layer that holds under concurrency and against
        // a hand-run UPDATE. The application can be wrong; these cannot.
        DB::statement(sprintf(
            "ALTER TABLE sellers ADD CONSTRAINT sellers_status_check CHECK (status IN ('%s'))",
            implode("', '", SellerStatus::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE sellers ADD CONSTRAINT sellers_currency_check CHECK (currency IN ('%s'))",
            implode("', '", Currency::values()),
        ));

        // A rejection without a reason is a dead end for the applicant: they
        // are told no and not told what to fix.
        DB::statement(
            "ALTER TABLE sellers ADD CONSTRAINT sellers_rejection_reason_check
             CHECK (status <> 'rejected' OR rejection_reason IS NOT NULL)"
        );

        // Reviewed exactly when a decision exists. Written as an equivalence
        // so it catches both halves: a decision with no timestamp, and a
        // timestamp on an application nobody has looked at.
        DB::statement(
            "ALTER TABLE sellers ADD CONSTRAINT sellers_reviewed_at_check
             CHECK ((status = 'pending') = (reviewed_at IS NULL))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
