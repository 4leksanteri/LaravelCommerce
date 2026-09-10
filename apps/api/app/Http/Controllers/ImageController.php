<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a product photograph.
 *
 * **The endpoint `config/filesystems.php` promised.** Laravel's
 * `filesystems.local.serve` would register `GET /storage/{path}` - outside
 * `api/v1`, which the Next.js server does not proxy, so nothing could reach it
 * anyway (ADR 0003). This is the route that does the job instead, under the one
 * prefix that is forwarded.
 *
 * Two kinds of image arrive here and they are treated differently:
 *
 * **On a public listing.** No signature, no session, cached for a year. These
 * are on a storefront and are meant to be seen by everybody; a CDN can serve
 * them without ever reaching this process again.
 *
 * **Anything else** - a draft, an unapproved shop, a deleted listing - requires
 * a **valid signature** and is never cached. The signed URL is handed out only
 * inside `ProductResource`, which only the listing's own seller can fetch, so
 * possession of a working URL already implies authorization and it expires
 * within the hour.
 *
 * The signature is checked here rather than by storage on purpose. It behaves
 * the same against a local disk, a bucket or an emulator, and it means the
 * bucket can stay entirely private while a CDN still caches the public case.
 *
 * ## What this does not protect
 *
 * An image that **was** public has been cached - by browsers, and by any CDN -
 * under an immutable URL. Unpublishing cannot recall those copies. Signing
 * protects what was never public; it does not retract what was.
 */
final class ImageController extends Controller
{
    /** A year, which is as long as `max-age` is allowed to mean anything. */
    private const int CACHE_SECONDS = 31_536_000;

    public function __invoke(Request $request, ProductImage $image): StreamedResponse
    {
        $public = $image->isOnAPublicListing();

        /*
         * Relative, because `ProductImage::url()` signs relatively - an
         * absolute signature would cover a host the proxy rewrote and would
         * never match (ADR 0003). The same reason the emailed verification
         * links use `signed:relative`.
         *
         * 404 rather than 403, consistently with how a draft behaves
         * everywhere else: saying "this exists but you may not see it" tells
         * somebody the key was real.
         */
        abort_if(! $public && ! $request->hasValidRelativeSignature(), 404);

        return Storage::disk($image->disk)->response($image->path, headers: [
            'Content-Type' => 'image/webp',

            // The bytes at a key never change - a re-encode makes a new row
            // with a new key - so a public one can be cached hard. Without
            // that, every thumbnail on a catalogue page is a PHP process.
            'Cache-Control' => $public
                ? sprintf('public, max-age=%d, immutable', self::CACHE_SECONDS)
                : 'private, no-store',
        ]);
    }
}
