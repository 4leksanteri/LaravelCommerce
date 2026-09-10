<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to send a parcel.
 *
 * A buyer's address book. What an **order** was sent to is snapshotted onto the
 * order itself and does not live here (ADR 0021) - somebody who moves house must
 * not rewrite where last year's parcels went.
 *
 * The field set is Stripe's `Address` shape plus a recipient and a telephone
 * number. That is not laziness: this marketplace is heading for Stripe Connect
 * (ADR 0015), and a schema that already matches what the payment processor
 * expects is one less translation to get wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Who the courier hands it to. Not the account holder's name: people
            // send things to their partner, their office, their parents.
            $table->string('name');

            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city');

            /*
             * Nullable, both of them, and this is where naive address schemas
             * go wrong.
             *
             * Plenty of countries have no state or province worth recording,
             * and several have no postal codes at all - Ireland had none until
             * 2015, and the UAE still does not. Requiring either makes the form
             * unfillable for somebody and teaches them to type "N/A".
             */
            $table->string('region')->nullable();
            $table->string('postal_code')->nullable();

            // ISO 3166-1 alpha-2, which is also what Stripe takes.
            $table->char('country', 2);

            // Couriers ask for one, and increasingly refuse to deliver without
            // it. Optional here because it is the buyer's to give.
            $table->string('phone')->nullable();

            $table->timestamps();

            // Every read is "this person's addresses", newest first.
            $table->index(['user_id', 'id']);
        });

        // Two letters, upper case. It does not verify the code names a real
        // country - that needs the ISO register, and ADR 0021 says why it is
        // not worth a dependency yet - but it does stop "united kingdom" and
        // "gb " reaching Stripe.
        DB::statement("ALTER TABLE addresses ADD CONSTRAINT addresses_country_check CHECK (country ~ '^[A-Z]{2}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
