"use client";

import { useRouter } from "next/navigation";
import { useEffect, useRef, useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FieldFrame } from "@/components/ui/field";
import { Textarea } from "@/components/ui/textarea";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { DisputeResolution, StaffDispute } from "@/lib/api/types";

/**
 * The decision the platform takes on one dispute (ADR 0051).
 *
 * **Both outcomes ask for a reason, unlike approving a shop.** Either way
 * somebody is about to be worse off than they hoped, and both parties are sent
 * the note - so the API requires it and this collects it. There is no quick
 * path for the "obvious" decision, because the obvious one still moves
 * somebody's money.
 *
 * **Neither can be undone.** Deciding a dispute ends the order: refunded
 * cancels it, released completes it, and both are final. The form is the pause
 * that a confirmation dialog would otherwise be.
 *
 * A 409 means another member of staff decided it first. The API's message is
 * shown and the queue is drawn again, exactly as the shop review queue does.
 */
const WORDING: Record<DisputeResolution, { button: string; label: string; hint: string }> = {
  refunded: {
    button: "Refund the buyer",
    label: "Why are you refunding it?",
    hint: "Both the buyer and the shop read this. The order is cancelled and the payment goes back in full.",
  },
  released: {
    button: "Release to the shop",
    label: "Why are you releasing it?",
    hint: "Both the buyer and the shop read this. The order completes and the payment goes to the shop, less the fee.",
  },
};

export function DisputeResolveActions({ dispute }: { dispute: StaffDispute }) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [deciding, setDeciding] = useState<DisputeResolution | null>(null);
  const [note, setNote] = useState("");
  const noteField = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    if (deciding) {
      noteField.current?.focus();
    }
  }, [deciding]);

  function decide(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    return submit(async () => {
      try {
        await apiFetch(`/admin/disputes/${dispute.id}/resolution`, {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ resolution: deciding, note }),
        });

        setDeciding(null);
        setNote("");
        router.refresh();
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent("/admin/disputes")}`);

          return;
        }

        // Decided by somebody else while this was open: draw the queue as it
        // now is.
        if (error instanceof ApiError && error.status === 409) {
          setDeciding(null);
          router.refresh();
        }

        throw error;
      }
    });
  }

  // A decided dispute leaves the queue, so this is drawn for open ones only.
  if (!dispute.is_open) {
    return null;
  }

  if (deciding) {
    const wording = WORDING[deciding];

    return (
      <form
        onSubmit={decide}
        noValidate
        aria-label={`${wording.button} for order ${dispute.order_reference}`}
        className="border-border bg-muted space-y-3 rounded-lg border p-4"
      >
        <FieldFrame label={wording.label} hint={wording.hint} errors={fieldErrors.note}>
          {(control) => (
            <Textarea
              {...control}
              ref={noteField}
              name="note"
              rows={3}
              required
              maxLength={2000}
              value={note}
              onChange={(event) => setNote(event.target.value)}
            />
          )}
        </FieldFrame>

        <div className="flex flex-wrap gap-2">
          <Button type="submit" size="sm" disabled={pending}>
            {pending ? "Deciding..." : wording.button}
          </Button>
          <Button variant="ghost" size="sm" disabled={pending} onClick={() => setDeciding(null)}>
            Go back
          </Button>
        </div>

        {failure ? <Alert tone="danger">{failure}</Alert> : null}
      </form>
    );
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        <Button size="sm" disabled={pending} onClick={() => setDeciding("refunded")}>
          {WORDING.refunded.button}
        </Button>
        <Button
          variant="secondary"
          size="sm"
          disabled={pending}
          onClick={() => setDeciding("released")}
        >
          {WORDING.released.button}
        </Button>
      </div>

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </div>
  );
}
