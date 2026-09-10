<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProductImage;
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
 * **Public, and keyed by something unguessable.** There is no authorization
 * check: an image on a published listing is public by definition, and one on a
 * draft is reachable only by somebody who already has its key. That is exactly
 * the model a public object-storage URL uses, and it is what these become when
 * they move to a bucket.
 *
 * The bytes at a key never change - a re-encode makes a new row with a new key -
 * so this can be cached hard. That matters: without it every thumbnail on a
 * catalogue page is a PHP process.
 */
final class ImageController extends Controller
{
    /** A year, which is as long as `max-age` is allowed to mean anything. */
    private const int CACHE_SECONDS = 31_536_000;

    public function __invoke(ProductImage $image): StreamedResponse
    {
        return Storage::disk($image->disk)->response($image->path, headers: [
            'Content-Type' => 'image/webp',
            'Cache-Control' => sprintf('public, max-age=%d, immutable', self::CACHE_SECONDS),
        ]);
    }
}
