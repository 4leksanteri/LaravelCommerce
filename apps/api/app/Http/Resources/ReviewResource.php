<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Review;
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
        ];
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
