<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review, as anybody reading a listing sees it.
 *
 * **The author is shortened, deliberately.** A product page is public and
 * indexable, and a full legal name published against a purchase is an exposure
 * nobody opted into by buying a camera. "Aino V." is enough for a reader to
 * tell two reviewers apart, which is all the name is for here.
 *
 * **There is no verified badge**, because there is nothing to distinguish. A
 * review cannot exist without a completed order behind it (ADR 0047), so every
 * one of these is a verified purchase and saying so on each would be noise.
 *
 * The order that earned it is not published. It is the proof, not the content,
 * and a reference somebody could quote at a shop is nobody else's business.
 */
final class ReviewResource extends JsonResource
{
    public function __construct(private readonly Review $review)
    {
        parent::__construct($review);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->review->id,
            'rating' => $this->review->rating,
            'body' => $this->review->body,
            'author' => $this->author(),

            'written_at' => $this->review->created_at?->toIso8601String(),

            // Whether it has been rewritten since. A reader is entitled to know
            // which they are looking at, and it is derived rather than stored.
            'was_edited' => $this->review->wasEdited(),

            /*
             * Whether this reader may report it (ADR 0054).
             *
             * False for a guest, who signs in first, and false on your own: the
             * way to take back what you wrote is to rewrite it, and offering
             * somebody a button to report themselves is noise.
             *
             * @var bool
             */
            'can_report' => $this->canReport($request),
        ];
    }

    /**
     * Anybody signed in, except the person who wrote it.
     *
     * Read from `user_id` rather than through the `user` relation, so this
     * costs nothing on a list - the author is already loaded for the name, but
     * the id is on the row either way.
     *
     * Annotated at the key as well as declared here, for the reason ADR 0047
     * records: the generator did not take `bool` from the declaration alone.
     */
    private function canReport(Request $request): bool
    {
        $viewer = $request->user();

        return $viewer instanceof User && $viewer->id !== $this->review->user_id;
    }

    /**
     * A first name and an initial, from whatever the account is called.
     *
     * An account named with one word keeps it; one named with three uses the
     * first and the last initial. Nothing here tries to parse a name properly,
     * because names do not parse - it shortens a string, and the worst case is
     * a slightly odd but harmless label.
     */
    private function author(): string
    {
        /*
         * A closed account keeps its reviews and loses its name (ADR 0058).
         *
         * Said explicitly rather than left to the shortening below, which takes
         * a first word and a last initial and would turn "Closed account" into
         * "Closed a." - a string that reads like somebody's name and is not
         * one.
         */
        if ($this->review->user->isClosed()) {
            return 'A former customer';
        }

        $parts = preg_split('/\s+/', trim($this->review->user->name)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        if ($parts === []) {
            return 'Someone';
        }

        $first = $parts[0];

        if (count($parts) === 1) {
            return $first;
        }

        $last = $parts[count($parts) - 1];

        return $first.' '.mb_strtoupper(mb_substr($last, 0, 1)).'.';
    }
}
