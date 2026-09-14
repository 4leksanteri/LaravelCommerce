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
import type { Report } from "@/lib/api/types";

/**
 * The decision the platform takes on one report (ADR 0054).
 *
 * **Upholding is the takedown**, and there is no separate control for removing
 * a listing anywhere - so every removal answers a report and carries the reason
 * typed here. That is what keeps moderation accountable rather than merely
 * possible.
 *
 * **Both outcomes ask for a reason**, as deciding a dispute does. Upholding
 * sends it to whoever wrote the thing that came down, and it is the only
 * explanation they get; dismissing writes to nobody, but the note is what the
 * next member of staff reads when the same thing is reported again.
 *
 * **Upholding cannot be undone.** There is no endpoint that puts a listing back
 * or unhides a review, so the form is the pause a confirmation dialog would
 * otherwise be.
 *
 * A subject that has gone can only be dismissed: the API answers 409 to
 * upholding one, because there is nothing left to take down.
 */
const WORDING = {
  uphold: {
    button: "Take it down",
    label: "Why are you taking it down?",
    hint: "Whoever posted it reads this, and it is the only explanation they get.",
  },
  dismiss: {
    button: "Leave it up",
    label: "Why is it staying up?",
    hint: "Nobody is written to. This is what the next person reads if it is reported again.",
  },
} as const;

type Decision = keyof typeof WORDING;

export function ReportDecideActions({ report }: { report: Report }) {
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
        await apiFetch(`/admin/reports/${report.id}/decision`, {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ upheld: deciding === "uphold", note }),
        });

        setDeciding(null);
        setNote("");
        router.refresh();
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent("/admin/reports")}`);

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

  // A decided report leaves the queue, so this is drawn for open ones only.
  if (!report.is_open) {
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
        {/*
         * Taking something down is the destructive one, so it is the quieter
         * control of the two. Nothing here is a default.
         */}
        <Button
          variant="secondary"
          size="sm"
          disabled={pending || report.subject === null}
          onClick={() => setDeciding("uphold")}
        >
          {WORDING.uphold.button}
        </Button>
        <Button size="sm" disabled={pending} onClick={() => setDeciding("dismiss")}>
          {WORDING.dismiss.button}
        </Button>
      </div>

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </div>
  );
}
