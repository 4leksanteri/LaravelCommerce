<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A page of something, and the only definition of what a page looks like here.
 *
 * **Laravel's default pagination envelope cannot be published by this API**, and
 * it was being published anyway. Left alone it emits:
 *
 * ```json
 * "links": { "first": "http://0.0.0.0:3000/api/v1/search?page=1", ... },
 * "meta":  { "path": "http://0.0.0.0:3000/api/v1/search", "links": [
 *     { "url": null, "label": "&laquo; Previous", "active": false }, ... ] }
 * ```
 *
 * Three things wrong with that, in descending order of seriousness.
 *
 * **It leaks an internal origin to the browser.** `0.0.0.0:3000` is where the
 * Next.js server binds inside its container. It reaches the paginator because
 * the proxy forwards its own host and `trustProxies(at: '*')` believes it, and
 * it is exactly what root `CLAUDE.md` section 9 forbids publishing. No browser
 * can reach that address, so the links are unusable as well as disclosing.
 *
 * **An absolute URL is the wrong shape regardless of which host it names.** The
 * browser calls relative `/api/v1/...` paths on the Next origin and the proxy
 * forwards them (ADR 0003); this application does not know the public origin
 * and must not guess at it. `ProductImage::url()` already reached this
 * conclusion for images and says so at length. This is the same rule applied to
 * the one other place that was building URLs.
 *
 * **`meta.links` is view furniture.** An array of `&laquo; Previous` labels
 * with an `active` flag is Blade pagination markup expressed as JSON, and this
 * application renders nothing.
 *
 * So a page carries four numbers and no URLs. The client already knows the path
 * because it made the request, and asks for the next page by putting `?page=` on
 * the one it used. `from` and `to` are left out as arithmetic over these.
 */
abstract class PaginatedCollection extends ResourceCollection
{
    /**
     * How a caller asks for the next page, described once.
     *
     * It has to be stated on each paginated action as a `#[QueryParameter]`:
     * `page` is read by Laravel's paginator straight from the request rather
     * than declared in a form request, so nothing in the code says it exists
     * and Scramble published `last_page` without publishing any way to reach
     * it. The description lives here so seven copies of it cannot disagree.
     */
    public const string PAGE_PARAMETER = 'Which page to return. Out of range is an empty set rather than an error.';

    /**
     * Replace the envelope. Laravel calls this when the collection wraps a
     * paginator, handing over what it would have sent as `$default`.
     *
     * Scramble resolves this method and types the response from what it
     * actually returns, so the generated contract follows the shape rather than
     * being told about it separately and drifting.
     *
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array{meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'meta' => [
                'current_page' => (int) $paginated['current_page'],
                'last_page' => (int) $paginated['last_page'],
                'per_page' => (int) $paginated['per_page'],
                'total' => (int) $paginated['total'],
            ],
        ];
    }
}
