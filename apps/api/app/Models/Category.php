<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What kind of thing a listing is.
 *
 * **The platform owns this list, not sellers.** A set every shop can add to
 * stops being a way to find anything and becomes fifty spellings of "bread".
 * There is no endpoint that writes one yet - they come from `CategorySeeder`
 * until there is an admin panel to manage them (ADR 0017).
 *
 * Two levels. `parent_id` gives "Food > Bread"; a third would need recursive
 * queries and unbounded breadcrumbs for a catalogue that has neither.
 *
 * @property-read Category|null $parent
 * @property-read Collection<int, Category> $children
 * @property-read Collection<int, Product> $products
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /** Categories are addressed by slug, in URLs and in route binding. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Top-level categories, in the order staff arranged them.
     *
     * @param  Builder<Category>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id')->orderBy('position')->orderBy('name');
    }

    /**
     * This category and everything under it.
     *
     * A shopper browsing "Food" expects to see the bread as well. Depth is
     * capped at two, so this is one level of children and never a recursion.
     *
     * @return array<int, int>
     */
    public function withDescendantIds(): array
    {
        return [$this->id, ...$this->children()->pluck('id')->all()];
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }
}
