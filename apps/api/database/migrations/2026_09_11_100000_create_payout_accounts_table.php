<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a shop's money goes: its Stripe connected account (ADR 0015, ADR 0031).
 *
 * One per shop. The row is a **copy** of what Stripe last said about the
 * account, refreshed after every change this application makes and on every
 * `account.updated` webhook, so a page can say where somebody stands without
 * asking Stripe on every load.
 *
 * What is not here is the point. No name, no date of birth, no ID number, no
 * IBAN, no identity document: those go to Stripe and are not kept. The last
 * four digits of the bank account are, because somebody checking where their
 * money goes needs something to recognise, and four digits identify nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_accounts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('seller_id')->unique()->constrained()->cascadeOnDelete();

            // acct_..., and the only way back to the account at Stripe.
            $table->string('stripe_account_id')->unique();

            // Fixed when the account is opened. Stripe does not move an account
            // from one country to another.
            $table->char('country', 2);

            // Stripe's own vocabulary - active, pending, inactive - kept as it
            // came rather than mapped. Stripe adds values, and a CHECK here
            // would turn the first new one into a webhook that fails forever.
            $table->string('transfers_status', 20);
            $table->boolean('payouts_enabled');

            // currently_due, past_due and errors, as Stripe sent them. Stored
            // raw and interpreted on read, so teaching PayoutField a new
            // requirement applies to every row at once rather than to each one
            // the next time it happens to change.
            $table->jsonb('requirements');
            $table->string('disabled_reason')->nullable();
            $table->timestamp('requirements_due_at')->nullable();

            $table->char('bank_account_last4', 4)->nullable();

            // Stripe's terms are accepted on our page rather than on one Stripe
            // hosts, so the evidence is ours to keep (ADR 0015).
            $table->timestamp('terms_accepted_at');
            $table->string('terms_accepted_ip', 45);
            $table->text('terms_accepted_user_agent')->nullable();

            // When the copy above was last taken from Stripe.
            $table->timestamp('synced_at');

            $table->timestamps();
        });

        DB::statement("ALTER TABLE payout_accounts ADD CONSTRAINT payout_accounts_country_check CHECK (country ~ '^[A-Z]{2}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_accounts');
    }
};
