<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A photograph, as anything that renders one needs it.
 *
 * The same shape for a seller and a shopper. There is nothing private about a
 * product photograph, and the internal columns - the disk it sits on, the path
 * within it, the numeric id - are absent because nothing outside this
 * application has any use for them.
 *
 * `width` and `height` are published so a client can reserve the space before
 * the bytes arrive. Without them every image on a page is a layout shift, and
 * `next/image` asks for them by name.
 */
final class ProductImageResource extends JsonResource
{
    public function __construct(private readonly ProductImage $image)
    {
        parent::__construct($image);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->image->uuid,
            'url' => $this->image->url(),
            'width' => $this->image->width,
            'height' => $this->image->height,

            // Null is honest here. An empty string would read to a screen
            // reader as a decorative image, which a product photograph is not.
            'alt_text' => $this->image->alt_text,

            'position' => $this->image->position,
        ];
    }
}
