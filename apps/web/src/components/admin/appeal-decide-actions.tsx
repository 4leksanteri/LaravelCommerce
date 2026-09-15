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
import type { Appeal } from "@/lib/api/types";

/**
 * The second look, and what comes of it (ADR 0059).
 *
 * **Upholding one is the only undo this platform has.** Nothing else puts a
 * listing back or unhides a review, so every reversal answers somebody's
 * argument and carries a decision somebody recorded - which is what ADR 0054
 * left open when it made taking things down one-way.
 *
 * **Neither button is the default, and here that is enforced rather than
 * stated.** The report queue makes its destructive decision the quieter
 * control, which works because only one side of it changes anything. Both
 * sides change something here: upholding puts a shop back on the marketplace,
 * dismissing leaves somebody stopped who has just argued they should not be.
 * Styling either as the obvious one would be the queue nudging its own
 * outcome, so they carry the same weight and the reader has to choose.
 *
 * **A subject that has gone can only be dismissed.** A morph carries no foreign
 * key, so a seller may delete the listing they were appealing about; the API
 * answers 409 to upholding one, because there is nothing left to put back.
 *
 * **Both outcomes ask for a reason, and a dismissal needs it most**: it is sent
 * to somebody whose shop is still stopped, and it is the only explanation they
 * get for the platform declining to explain twice.
 */
const WORDING = {
  uphold: {
    button: "Uphold the appeal",
    label: "Why is the decision being reversed?",
    hint: "Whoever appealed reads this, and what was stopped comes back.",
  },
  dismiss: {
    button: "Let the decision stand",
    label: "Why does the decision stand?",
    hint: "Whoever appealed reads this. It is the only explanation they get.",
  },
} as const;

type Decision = keyof typeof WORDING;

export function AppealDecideActions({ appeal }: { appeal: Appeal }) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [deciding, setDeciding] = useState<Decision | null>(null);
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
        await apiFetch(`/admin/appeals/${appeal.id}/decision`, {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ upheld: deciding === "uphold", note }),
        });

        setDeciding(null);
        setNote("");
        router.refresh();
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent("/admin/appeals")}`);

          return;
        }

        // Decided by somebody else while this was open, or the subject was
        // deleted since: draw the queue as it now is.
        if (error instanceof ApiError && error.status === 409) {
          setDeciding(null);
          router.refresh();
        }

        throw error;
      }
    });
  }

  // A decided appeal leaves the queue, so this is drawn for open ones only.
  if (!appeal.is_open) {
    return null;
  }

  if (deciding) {
    const wording = WORDING[deciding];

    return (
      <form
        onSubmit={decide}
        noValidate
        aria-label={wording.button}
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
        <Button
          variant="secondary"
          size="sm"
          disabled={pending || appeal.subject === null}
          onClick={() => setDeciding("uphold")}
        >
          {WORDING.uphold.button}
        </Button>
        <Button
          variant="secondary"
          size="sm"
          disabled={pending}
          onClick={() => setDeciding("dismiss")}
        >
          {WORDING.dismiss.button}
        </Button>
      </div>

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </div>
  );
}
