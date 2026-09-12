"use client";

import { useRouter } from "next/navigation";
import { useEffect, useId, useRef, useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FieldFrame } from "@/components/ui/field";
import { Textarea } from "@/components/ui/textarea";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Shop } from "@/lib/api/types";

/**
 * The decision staff take on one application: let the shop trade, or turn it
 * down and say why.
 *
 * **Drawn from `can_review`**, which is the policy's answer and includes that
 * staff may not review their own application (ADR 0008). Nothing here reads a
 * status or a role to decide.
 *
 * **Both ask first, and neither can be undone.** A decision is final either way
 * - a reviewed application cannot be reviewed again - and it changes what
 * somebody else is allowed to do for a living. Approving asks a plain question;
 * turning one down asks for the reason, which the applicant reads and needs in
 * order to apply again (ADR 0007), so that form is both the reason and the
 * pause.
 *
 * **A 409 means somebody else got there first.** Two reviewers can have the
 * queue open, and the API refuses the second with the decision that was
 * recorded. Its message is shown and the queue is drawn again.
 */
export function ShopReviewActions({ shop }: { shop: Shop }) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [asking, setAsking] = useState<"approve" | "reject" | null>(null);
  const [reason, setReason] = useState("");
  const confirm = useRef<HTMLButtonElement>(null);
  const reasonField = useRef<HTMLTextAreaElement>(null);
  const questionId = useId();

  useEffect(() => {
    if (asking === "approve") {
      confirm.current?.focus();
    }

    if (asking === "reject") {
      reasonField.current?.focus();
    }
  }, [asking]);

  async function decide(decision: "approval" | "rejection", body?: object) {
    await submit(async () => {
      try {
        await apiFetch(`/admin/sellers/${shop.id}/${decision}`, {
          method: "POST",
          ...(body
            ? { headers: { "content-type": "application/json" }, body: JSON.stringify(body) }
            : {}),
        });

        setAsking(null);
        setReason("");
        router.refresh();
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent("/admin/shops")}`);

          return;
        }

        // Already decided by another reviewer: draw the queue as it now is.
        if (error instanceof ApiError && error.status === 409) {
          setAsking(null);
          router.refresh();
        }

        throw error;
      }
    });
  }

  function reject(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    return decide("rejection", { reason });
  }

  if (!shop.can_review) {
    return null;
  }

  return (
    <div className="space-y-3">
      {asking === "approve" ? (
        <div
          role="group"
          aria-labelledby={questionId}
          className="border-border bg-muted space-y-3 rounded-lg border p-4"
        >
          <p id={questionId} className="text-sm leading-relaxed">
            Let {shop.shop_name} open? It will be able to publish listings and take orders, and the
            decision cannot be undone.
          </p>
          <div className="flex flex-wrap gap-2">
            <Button ref={confirm} size="sm" disabled={pending} onClick={() => decide("approval")}>
              {pending ? "Approving..." : "Yes, let it trade"}
            </Button>
            <Button variant="ghost" size="sm" disabled={pending} onClick={() => setAsking(null)}>
              Go back
            </Button>
          </div>
        </div>
      ) : asking === "reject" ? (
        <form
          onSubmit={reject}
          noValidate
          aria-label={`Turn down ${shop.shop_name}`}
          className="border-border bg-muted space-y-3 rounded-lg border p-4"
        >
          <FieldFrame
            label="Why are you turning it down?"
            hint="The applicant reads this, and it is what they have to go on if they apply again."
            errors={fieldErrors.reason}
          >
            {(control) => (
              <Textarea
                {...control}
                ref={reasonField}
                name="reason"
                rows={3}
                required
                value={reason}
                onChange={(event) => setReason(event.target.value)}
              />
            )}
          </FieldFrame>
          <div className="flex flex-wrap gap-2">
            <Button type="submit" size="sm" disabled={pending}>
              {pending ? "Sending..." : "Send the decision"}
            </Button>
            <Button variant="ghost" size="sm" disabled={pending} onClick={() => setAsking(null)}>
              Keep it in the queue
            </Button>
          </div>
        </form>
      ) : (
        <div className="flex flex-wrap gap-2">
          <Button size="sm" disabled={pending} onClick={() => setAsking("approve")}>
            Approve
          </Button>
          <Button
            variant="secondary"
            size="sm"
            disabled={pending}
            onClick={() => setAsking("reject")}
          >
            Turn it down
          </Button>
        </div>
      )}

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </div>
  );
}
