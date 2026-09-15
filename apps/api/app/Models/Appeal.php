<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\AppealFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Somebody answering back about a decision the platform took (ADR 0059).
 *
 * Nothing is fillable. The reason comes from a request, but it arrives through
 * an action that has already established what is being appealed and that this
 * person owns it; everything about the decision is the platform's to set.
 *
 * **What it points at is the thing that was stopped**, not the report that
 * stopped it. A takedown answers a report and a suspension answers nothing, so
 * the report cannot be the common parent - a suspended shop, a removed listing
 * and a hidden review can. That makes this the second morph in this repository,
 * and it carries the same caveat as the first: no foreign key, so an appeal can
 * outlive its subject, and `subjectIsGone()` is how a queue says so rather than
 * rendering a blank.
 *
 * @property-read Model|null $appealable
 * @property-read User $user
 * @property-read User|null $reviewedBy
 * @property int $id
 * @property string $appealable_type
 * @property int $appealable_id
 * @property int $user_id
 * @property string $reason
 * @property CarbonInterface|null $reviewed_at
 * @property bool|null $upheld
 * @property string|null $outcome_note
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Appeal extends Model
{
    /** @use HasFactory<AppealFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'upheld' => 'boolean',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function appealable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Who is answering back.
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
     * the three columns beside it - the same reason `Report::isOpen()` and
     * `Dispute::isOpen()` read that way.
     */
    public function isOpen(): bool
    {
        return $this->reviewed_at === null;
    }

    /**
     * Whether an upheld appeal would have anything left to lift.
     *
     * A morph has no foreign key, so this is real rather than defensive: a
     * seller may delete a listing they were appealing about, which is a
     * perfectly reasonable thing to do while waiting.
     */
    public function subjectIsGone(): bool
    {
        return $this->appealable === null;
    }
}
