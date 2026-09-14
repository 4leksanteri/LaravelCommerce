"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FieldFrame } from "@/components/ui/field";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { ReportReason } from "@/lib/api/types";

/**
 * Reporting a listing or a review (ADR 0054).
 *
 * **Whether this is drawn at all is the API's answer.** `can_report` is false
 * for a guest, who signs in first, and false on your own listing or your own
 * review - and nothing here re-derives either. The page passes `signInHref`
 * when there is nobody signed in, which is the pattern ADR 0028 settled: a
 * public page whose one action needs a session offers the way to get one rather
 * than hiding the action.
 *
 * **Reporting is not accusing, and this must not pretend otherwise.** Nothing
 * happens to the listing when a report is filed - it stays on sale until a
 * person decides - so the confirmation says it is being looked at, and never
 * that anything has been taken down.
 *
 * A 409 is the ordinary case rather than a failure: it means this person has
 * already reported this and the platform has not got to it yet. The API's own
 * sentence says exactly that, so it is shown as it arrives.
 *
 * `Record<ReportReason, string>` rather than a loose map, so a sixth reason
 * added to the enum breaks this build instead of rendering a blank option.
 */
const REASONS: Record<ReportReason, string> = {
  counterfeit: "It is not what it claims to be",
  prohibited: "It should not be sold here",
  abusive: "It is abusive",
  spam: "It is spam, or posted over and over",
  other: "Something else",
};

export function ReportControl({
  path,
  subject,
  signInHref = null,
}: {
  /** The API path this reports to, already built by whoever knows the slugs. */
  path: string;
  /** What is being reported, for the accessible name. "this listing". */
  subject: string;
  /** Where to sign in, when nobody is. Null when somebody already is. */
  signInHref?: string | null;
}) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState<ReportReason>("counterfeit");
  const [note, setNote] = useState("");
  const [filed, setFiled] = useState(false);

  if (signInHref) {
    return (
      <p className="text-muted-foreground text-xs">
        <Link href={signInHref} className="hover:text-foreground underline">
          Sign in
        </Link>{" "}
        to report {subject}.
      </p>
    );
  }

  function send(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    return submit(async () => {
      try {
        await apiFetch(path, {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ reason, note: note.trim() === "" ? null : note }),
        });

        setFiled(true);
        setOpen(false);
        setNote("");
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent(window.location.pathname)}`);

          return;
        }

        throw error;
      }
    });
  }

  // Said once, and it stays said. Nothing is refreshed: what was reported is
  // still on sale, and redrawing would offer the button again as though the
  // report had not happened.
  if (filed) {
    return (
      <p className="text-muted-foreground text-xs">
        Reported. Somebody will look at it - it stays on sale until they do.
      </p>
    );
  }

  if (!open) {
    return (
      <div className="space-y-2">
        <button
          type="button"
          onClick={() => setOpen(true)}
          className="text-muted-foreground hover:text-foreground text-xs underline"
        >
          Report {subject}
        </button>

        {failure ? <Alert tone="danger">{failure}</Alert> : null}
      </div>
    );
  }

  return (
    <form
      onSubmit={send}
      noValidate
      aria-label={`Report ${subject}`}
      className="border-border bg-muted space-y-3 rounded-lg border p-4"
    >
      <FieldFrame label="What is wrong with it?" errors={fieldErrors.reason}>
        {(control) => (
          <Select
            {...control}
            name="reason"
            value={reason}
            onChange={(event) => setReason(event.target.value as ReportReason)}
          >
            {Object.entries(REASONS).map(([value, wording]) => (
              <option key={value} value={value}>
                {wording}
              </option>
            ))}
          </Select>
        )}
      </FieldFrame>

      <FieldFrame
        label={reason === "other" ? "Tell us what is wrong" : "Anything to add? (optional)"}
        hint={
          reason === "other"
            ? "Say what the problem is. Without it there is nothing to act on."
            : "Only if the reason above does not cover it."
        }
        errors={fieldErrors.note}
      >
        {(control) => (
          <Textarea
            {...control}
            name="note"
            rows={3}
            maxLength={2000}
            required={reason === "other"}
            value={note}
            onChange={(event) => setNote(event.target.value)}
          />
        )}
      </FieldFrame>

      <div className="flex flex-wrap gap-2">
        <Button type="submit" size="sm" disabled={pending}>
          {pending ? "Reporting..." : "Report it"}
        </Button>
        <Button variant="ghost" size="sm" disabled={pending} onClick={() => setOpen(false)}>
          Cancel
        </Button>
      </div>

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </form>
  );
}
