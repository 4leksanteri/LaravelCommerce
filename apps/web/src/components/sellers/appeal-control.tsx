"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FieldFrame } from "@/components/ui/field";
import { Textarea } from "@/components/ui/textarea";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

/**
 * Answering back about a decision the platform took (ADR 0059).
 *
 * **Whether this is offered at all is the API's answer.** `can_appeal` already
 * accounts for all three conditions - the thing is stopped, the viewer owns it,
 * and no appeal is open yet - and nothing here re-derives any of them.
 *
 * **Appealing changes nothing, and this must not pretend otherwise.** The shop
 * stays suspended and the listing stays down until a person decides, so every
 * sentence below says that a second look is coming, never that anything has
 * been undone. It is the same promise `ReportControl` makes from the other
 * side of moderation, and for the same reason: a control that looked like it
 * lifted the sanction would be appealed the moment any sanction landed.
 *
 * **An appeal already open is said out loud rather than drawn as nothing.**
 * `can_appeal` goes false once one is open, so a page holding only that field
 * could not tell "there is nothing to appeal" from "you already did" - and
 * somebody coming back the next day would find the notice, no form, and no
 * acknowledgement their argument had ever arrived. `has_open_appeal` is what
 * separates the two, and this draws the difference.
 */
export function AppealControl({
  path,
  subject,
  whileWaiting,
  canAppeal,
  hasOpenAppeal,
}: {
  /** The API path this appeals to, already built by whoever knows the ids. */
  path: string;
  /** What is being appealed, for the button and the form's accessible name. */
  subject: string;
  /** What stays true until somebody decides. Said back on the confirmation. */
  whileWaiting: string;
  /** The API's answer. False when there is nothing to appeal, or one is open. */
  canAppeal: boolean;
  /** Whether this viewer already has an appeal waiting about this. */
  hasOpenAppeal: boolean;
}) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  const [raised, setRaised] = useState(false);

  function send(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    return submit(async () => {
      try {
        await apiFetch(path, {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ reason }),
        });

        setRaised(true);
        setOpen(false);
        setReason("");
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent(window.location.pathname)}`);

          return;
        }

        // A 409 is the ordinary case rather than a failure: somebody appealed
        // from another tab, or the sanction has already been lifted. The API's
        // own sentence says which, so it is shown as it arrives.
        throw error;
      }
    });
  }

  /*
   * Said once, and it stays said. Nothing is refreshed, for the reason
   * `ReportControl` gives: what was appealed is still stopped, and redrawing
   * the page would offer the button again as though the appeal had not
   * happened.
   */
  if (raised || hasOpenAppeal) {
    return (
      <p className="text-muted-foreground text-sm leading-relaxed">
        You have appealed, and somebody will look at it again. {whileWaiting}
      </p>
    );
  }

  if (!canAppeal) {
    return null;
  }

  if (!open) {
    return (
      <div className="space-y-2">
        <Button variant="secondary" size="sm" onClick={() => setOpen(true)}>
          Appeal {subject}
        </Button>

        {failure ? <Alert tone="danger">{failure}</Alert> : null}
      </div>
    );
  }

  return (
    <form
      onSubmit={send}
      noValidate
      aria-label={`Appeal ${subject}`}
      className="border-border bg-muted space-y-3 rounded-lg border p-4"
    >
      <FieldFrame
        label="Why was this decision wrong?"
        hint="A person reads this, so give them something to weigh - at least a sentence."
        errors={fieldErrors.reason}
      >
        {(control) => (
          <Textarea
            {...control}
            name="reason"
            rows={4}
            required
            maxLength={2000}
            value={reason}
            onChange={(event) => setReason(event.target.value)}
          />
        )}
      </FieldFrame>

      <div className="flex flex-wrap gap-2">
        <Button type="submit" size="sm" disabled={pending}>
          {pending ? "Sending..." : "Send the appeal"}
        </Button>
        <Button variant="ghost" size="sm" disabled={pending} onClick={() => setOpen(false)}>
          Cancel
        </Button>
      </div>

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </form>
  );
}
