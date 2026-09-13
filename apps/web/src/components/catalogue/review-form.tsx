"use client";

import { useRouter } from "next/navigation";
import { useId, useState } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Resource, Review } from "@/lib/api/types";

/**
 * Leaving a review, or changing the one you left (ADR 0047).
 *
 * **Whether this is drawn at all is the API's answer.** `can_review` and
 * `your_review` come from the listing, and nothing here works out whether
 * somebody has a completed order - the browser cannot see an order history, and
 * a copy of that rule is the one that goes stale (root `CLAUDE.md` section 4).
 *
 * **The rating is radios, not a row of clickable stars.** Five inputs with real
 * labels are reachable by keyboard and announced by a screen reader as "3 of 5"
 * without any of the work that makes a custom star widget accessible. The stars
 * beside them are decoration.
 *
 * **A revision replaces the verdict**, which is why the words are sent whether
 * or not they changed: clearing the box means "I have removed what I said", and
 * the API takes a full body on PATCH for exactly that reason.
 */
export function ReviewForm({
  shopSlug,
  productSlug,
  existing,
}: {
  shopSlug: string;
  productSlug: string;
  existing: Review | null;
}) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [rating, setRating] = useState<number>(existing?.rating ?? 5);
  const [body, setBody] = useState<string>(existing?.body ?? "");
  const [saved, setSaved] = useState(false);
  const group = useId();
  const bodyId = useId();

  const path = `/shops/${encodeURIComponent(shopSlug)}/products/${encodeURIComponent(productSlug)}`;

  async function send(event: React.FormEvent) {
    event.preventDefault();
    setSaved(false);

    await submit(async () => {
      try {
        await apiFetch<Resource<Review>>(`${path}/reviews`, {
          method: existing ? "PATCH" : "POST",
          body: JSON.stringify({ rating, body: body.trim() === "" ? null : body }),
          headers: { "content-type": "application/json" },
        });

        setSaved(true);
        router.refresh();
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent(path)}`);

          return;
        }

        // Already reviewed, or an order that no longer entitles this. The page
        // is drawn again from what the API says now rather than from what it
        // said when this form was rendered.
        if (error instanceof ApiError && error.status === 409) {
          router.refresh();
        }

        throw error;
      }
    });
  }

  return (
    <form
      onSubmit={send}
      aria-label={existing ? "Change your review" : "Write a review"}
      className="space-y-4"
    >
      <fieldset className="space-y-2">
        <legend className="text-sm font-medium">Your rating</legend>

        <div className="flex flex-wrap items-center gap-3">
          {[1, 2, 3, 4, 5].map((value) => (
            <label key={value} className="flex items-center gap-1.5 text-sm">
              <input
                type="radio"
                name={group}
                value={value}
                checked={rating === value}
                onChange={() => setRating(value)}
                className="accent-primary size-4"
              />
              {value}
            </label>
          ))}
        </div>

        {fieldErrors.rating ? (
          <p className="text-destructive text-sm">{fieldErrors.rating[0]}</p>
        ) : null}
      </fieldset>

      <div className="space-y-1.5">
        <label htmlFor={bodyId} className="text-sm font-medium">
          What you thought <span className="text-muted-foreground">(optional)</span>
        </label>
        <textarea
          id={bodyId}
          value={body}
          onChange={(event) => setBody(event.target.value)}
          rows={4}
          maxLength={2000}
          className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm outline-none focus-visible:ring-2"
        />
        {fieldErrors.body ? (
          <p className="text-destructive text-sm">{fieldErrors.body[0]}</p>
        ) : null}
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" disabled={pending}>
          {existing ? "Save changes" : "Leave review"}
        </Button>
        {saved ? <span className="text-positive text-sm font-medium">Saved.</span> : null}
      </div>

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </form>
  );
}
