<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DecisionKind;
use Carbon\CarbonInterface;
use Database\Factories\PlatformDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One decision the platform took about a shop, kept for good (ADR 0060).
 *
 * **Append-only.** `UPDATED_AT` is null because there is no such column: a row
 * is written when the decision is taken and never edited. Nothing here is
 * fillable either - every value comes from an action that has already
 * established who decided and what they decided about.
 *
 * It exists because the sanctions themselves do not survive being lifted.
 * `ReinstateShop` nulls the suspension columns and `DecideAppeal` nulls the
 * removal ones, both because a CHECK constraint ties them to a status that has
 * changed - so without this table a shop stopped three times reads as a shop
 * never stopped at all.
 *
 * @property-read Seller $seller
 * @property-read Model|null $subject
 * @property-read User|null $decidedBy
 * @property int $id
 * @property int $seller_id
 * @property DecisionKind $kind
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $reason
 * @property int|null $decided_by
 * @property CarbonInterface|null $created_at
 */
class PlatformDecision extends Model
{
    /** @use HasFactory<PlatformDecisionFactory> */
    use HasFactory;

    /**
     * Append-only: there is no `updated_at` column to maintain.
     *
     * Untyped, matching the constant it overrides. Laravel declares
     * `const UPDATED_AT = 'updated_at'` without a type, and narrowing an
     * inherited constant is a compatibility question worth not having.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DecisionKind::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * The shop this counts against. Never null - see the migration.
     *
     * @return BelongsTo<Seller, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * What it was about: the shop, a listing, a dispute or an appeal.
     *
     * A morph carries no foreign key, so this resolves to null once a seller
     * deletes the listing they were punished over. The resource says so rather
     * than rendering a blank row.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The member of staff who decided it.
     *
     * Recorded and never published, for the reason a dispute's `resolved_by`
     * is not: the decision is the platform's rather than an individual's.
     *
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
