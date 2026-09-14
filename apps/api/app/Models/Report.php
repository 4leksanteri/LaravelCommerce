<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportReason;
use Carbon\CarbonInterface;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Somebody's statement that something here should not be (ADR 0054).
 *
 * Nothing is fillable. The reason and the note come from a request, but they
 * arrive through an action that has already established what is being reported
 * and that the reporter may see it; everything about the decision is the
 * platform's to set.
 *
 * **What it points at is a morph**, which is the one place this repository
 * reaches for that. A listing and a review are both reportable today and a
 * message is named as next (ADR 0050), and a morph cannot carry a foreign key -
 * so a report can outlive its subject. `subjectIsGone()` is how the queue says
 * so rather than rendering a blank.
 *
 * @property-read Model|null $reportable
 * @property-read User $user
 * @property-read User|null $reviewedBy
 * @property int $id
 * @property string $reportable_type
 * @property int $reportable_id
 * @property int $user_id
 * @property ReportReason $reason
 * @property string|null $note
 * @property CarbonInterface|null $reviewed_at
 * @property bool|null $upheld
 * @property string|null $outcome_note
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'reviewed_at' => 'datetime',
            'upheld' => 'boolean',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Who reported it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The member of staff who decided it.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Whether it is still waiting on the platform.
     *
     * Derived from `reviewed_at` rather than stored, so it cannot disagree with
     * the three columns beside it - the same reason `Dispute::isOpen()` reads
     * that way.
     */
    public function isOpen(): bool
    {
        return $this->reviewed_at === null;
    }

    /**
     * Whether what this points at has gone since it was reported.
     *
     * A morph has no foreign key, so this is a real possibility rather than a
     * defensive one: a seller may delete a listing somebody flagged. The queue
     * still shows the report, because a shop deleting what it was reported for
     * is itself worth knowing.
     */
    public function subjectIsGone(): bool
    {
        return $this->reportable === null;
    }
}
