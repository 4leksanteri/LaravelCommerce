<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * A photograph of a listing, in WebP, produced by this application.
 *
 * Nothing here is fillable. Width, height, byte size and path all describe a
 * file the conversion produced, and a request body that could set them could
 * describe a file that does not exist.
 *
 * @property-read Product|null $product
 */
class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    /**
     * The public identifier, everywhere.
     *
     * Route model binding resolves on `uuid`, so no numeric id ever appears in
     * a URL. An image URL is handed out and cached, and a sequential one would
     * let anybody walk the catalogue - including photographs on listings that
     * are still drafts.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'byte_size' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Where a client should fetch it.
     *
     * **One place, deliberately.** Today every image is on a private local disk
     * and is streamed by an endpoint under `api/v1`, because the proxy forwards
     * that prefix and nothing else (ADR 0003). When images move to object
     * storage the answer becomes a bucket URL for images on that disk and stays
     * this one for everything uploaded before the move - which is why each row
     * records its own disk, and why every caller asks this method rather than
     * building a path.
     *
     * **Relative, not absolute.** An absolute URL here would be built from
     * `APP_URL`, which is this application's own origin - and no browser can
     * reach that. The browser calls relative `/api/v1/...` paths on the Next.js
     * origin and the proxy forwards them, which is the whole arrangement in
     * ADR 0003. It is also what lets `next/image` treat these as local and
     * optimise them without an allowlist.
     */
    public function url(): string
    {
        if ($this->isOnAPublicListing()) {
            return route('images.show', ['image' => $this->uuid], absolute: false);
        }

        /*
         * Anything not on sale is served only against a signature.
         *
         * An unguessable key alone is not enough for a draft: URLs leak into
         * browser history, referrer headers, logs and screenshots, and one that
         * never expires is a permanent key. This one stops working within the
         * hour.
         *
         * The signature is checked by **this** application rather than by
         * storage, which is what keeps the behaviour identical whether the
         * bytes are on a local disk, in a bucket, or in an emulator - and what
         * lets a CDN cache the public case without understanding any of it.
         *
         * Relative, like the emailed verification links, because the signature
         * must not depend on the host the proxy forwarded (ADR 0003).
         */
        return URL::temporarySignedRoute(
            'images.show',
            now()->addMinutes((int) config('images.signed_url_minutes')),
            ['image' => $this->uuid],
            absolute: false,
        );
    }

    /**
     * Whether a shopper could see the listing this belongs to.
     *
     * The product's own answer (`Product::isPublic()`), so a photograph is
     * public exactly when its listing is - published, in an approved shop, not
     * deleted. A trashed product resolves to null through the relation, which
     * is correctly not public.
     *
     * `Product::images()` chaperones, so this costs nothing on a page that
     * loaded the products it is rendering.
     */
    public function isOnAPublicListing(): bool
    {
        $product = $this->product;

        return $product instanceof Product && $product->isPublic();
    }
}
