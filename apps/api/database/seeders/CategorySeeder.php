<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The category list.
 *
 * **This is the admin panel, for now.** Staff own the set (ADR 0017) and there
 * is no endpoint that writes one, so it lives here until there is. That is a
 * deliberate half-step rather than an oversight: sellers need something to
 * choose from and shoppers need something to browse, and neither needs a CRUD
 * screen to exist first.
 *
 * Idempotent, keyed on the slug, so running it twice changes nothing and
 * adding a category to the array below is a re-run rather than a migration.
 */
class CategorySeeder extends Seeder
{
    /**
     * Two levels, which is as deep as the schema goes.
     *
     * @var array<string, list<string>>
     */
    private const array TREE = [
        'Food and drink' => ['Bread and baking', 'Coffee and tea', 'Preserves', 'Confectionery'],
        'Home' => ['Kitchen', 'Textiles', 'Ceramics', 'Candles'],
        'Craft and hobby' => ['Yarn and fibre', 'Tools', 'Kits'],
        'Jewellery' => ['Rings', 'Necklaces', 'Earrings'],
        'Clothing' => ['Knitwear', 'Accessories', 'Footwear'],
        'Art and prints' => ['Original work', 'Prints', 'Illustration'],
    ];

    public function run(): void
    {
        $position = 0;

        foreach (self::TREE as $parentName => $childNames) {
            $parent = $this->upsert($parentName, null, $position++);

            $childPosition = 0;

            foreach ($childNames as $childName) {
                $this->upsert($childName, $parent->id, $childPosition++);
            }
        }
    }

    private function upsert(string $name, ?int $parentId, int $position): Category
    {
        $category = Category::query()->firstOrNew(['slug' => Str::slug($name)]);

        $category->forceFill([
            'name' => $name,
            'slug' => Str::slug($name),
            'parent_id' => $parentId,
            'position' => $position,
        ])->save();

        return $category;
    }
}
