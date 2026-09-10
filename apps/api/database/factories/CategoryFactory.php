<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * A top-level category. `under()` puts one beneath another, which is as
     * deep as the tree goes.
     *
     * @return array<model-property<Category>, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(implode(' ', (array) fake()->unique()->words(2)));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'parent_id' => null,
            'position' => 0,
        ];
    }

    public function under(Category $parent): static
    {
        return $this->state(fn (): array => ['parent_id' => $parent->id]);
    }
}
