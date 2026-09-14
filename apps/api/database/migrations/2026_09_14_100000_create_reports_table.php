<?php

declare(strict_types=1);

use App\Enums\ReportReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody says something on this marketplace should not be here (ADR 0054).
 *
 * **The first polymorphic table in this repository**, and that is a decision
 * rather than a habit. Nothing else here uses `morphs()`, and root `CLAUDE.md`
 * section 2 is explicit about not introducing architecture ahead of need - but
 * two reportable things exist today, a listing and a review, and ADR 0050
 * already names a third. Two parallel tables would mean two queues, two
 * resources and two controllers for one staff activity, which is the
 * duplication that actually costs something.
 *
 * The price is that the database cannot enforce the reference: there is no
 * foreign key on a morph, so a report can outlive what it points at. That is
 * handled where it matters rather than pretended away - the queue resolves the
 * subject and says so when it has gone.
 *
 * **One open report per person per thing.** Reporting something twice is not
 * two opinions, and the partial unique index below says so rather than leaving
 * it to the code that happens to insert. Once a report is decided, the same
 * person may report the same thing again - it may have changed since.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();

            // What is being reported: `reportable_type` and `reportable_id`.
            $table->morphs('reportable');

            // Who said so. Cascades: a report is somebody's statement, and an
            // account that is gone has not made one.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('reason', 20);

            // The reporter's own words. Optional, because the reason is often
            // the whole of it - "this is counterfeit" needs no essay.
            $table->text('note')->nullable();

            // All four move together when staff decide, exactly as a dispute's
            // resolution does.
            $table->timestamp('reviewed_at')->nullable();
            $table->boolean('upheld')->nullable();
            $table->text('outcome_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The queue: what is still open, oldest first, so the thing
            // somebody flagged first is looked at first.
            $table->index(['reviewed_at', 'id']);
        });

        DB::statement(sprintf(
            "ALTER TABLE reports ADD CONSTRAINT reports_reason_check CHECK (reason IN ('%s'))",
            implode("', '", ReportReason::values()),
        ));

        /*
         * A decision is whole, or it has not happened. Equivalences in both
         * directions, so a verdict with no date and a date with no verdict are
         * each impossible - the same shape `disputes_resolution_is_whole` has.
         */
        DB::statement(
            'ALTER TABLE reports ADD CONSTRAINT reports_review_is_whole CHECK (
                (reviewed_at IS NULL) = (upheld IS NULL)
                AND (reviewed_at IS NULL) = (outcome_note IS NULL)
                AND (reviewed_at IS NULL) = (reviewed_by IS NULL)
            )'
        );

        DB::statement(
            'ALTER TABLE reports ADD CONSTRAINT reports_note_not_blank
             CHECK (note IS NULL OR length(btrim(note)) > 0)'
        );

        DB::statement(
            'ALTER TABLE reports ADD CONSTRAINT reports_outcome_note_not_blank
             CHECK (outcome_note IS NULL OR length(btrim(outcome_note)) > 0)'
        );

        /*
         * One **open** report per person per thing. Partial, so a decided
         * report does not block a later one: the listing may have been edited
         * since, and refusing the second report would be the platform telling
         * somebody it already knows.
         */
        DB::statement(
            'CREATE UNIQUE INDEX reports_one_open_per_reporter
             ON reports (user_id, reportable_type, reportable_id)
             WHERE reviewed_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
