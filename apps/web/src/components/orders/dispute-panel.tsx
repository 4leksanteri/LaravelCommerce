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
import type { Dispute } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";

/**
 * The dispute on one order, from whichever side is reading it (ADR 0051).
 *
 * **One component, because there is one dispute.** It shows the one that was
 * raised, or offers to raise one, and which of those it does is the API's
 * answer rather than a rule worked out here: `can_dispute` is true only while
 * the order has shipped and its money is still held, which the browser cannot
 * see.
 *
 * **A shop never raises one.** `SellerOrderResource` publishes no
 * `can_dispute`, so the shop's copy of this is read-only by construction rather
 * than by a check - a shop disputing its own sale would be disputing a payout
 * it is waiting for.
 *
 * Both sides read the same thing, reason and decision included. A complaint the
 * other party cannot read is one they cannot answer.
 */
type Props = {
  dispute: Dispute | null;
  /** The API's answer, never re-derived. Always false on the shop's side. */
  canDispute?: boolean;
  /** The API's address for the order: `/orders/X` or `/seller/orders/X`. */
  endpoint: string;
  /** This page's own address, for sending somebody back after signing in. */
  page: string;
  viewer: "buyer" | "shop";
};

/**
 * What the decision meant, from the reader's side.
 *
 * Keyed by the resolution, so a third outcome is a type error here rather than
 * a blank space on a page about somebody's money.
 */
const OUTCOME: Record<NonNullable<Dispute["resolution"]>, Record<"buyer" | "shop", string>> = {
  refunded: {
    buyer: "Decided in your favour. The order was cancelled and refunded in full.",
    shop: "Decided in the buyer's favour. The order was cancelled and refunded, and there is no payout for it.",
  },
  released: {
    buyer:
      "Decided in the shop's favour. The order is complete and the payment was released to them.",
    shop: "Decided in your favour. The order is complete and the payment was released to you.",
  },
};

export function DisputePanel({ dispute, canDispute = false, endpoint, page, viewer }: Props) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [asking, setAsking] = useState(false);
  const [reason, setReason] = useState("");
  const reasonField = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    if (asking) {
      reasonField.current?.focus();
    }
  }, [asking]);

  function raise(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    return submit(async () => {
      try {
        await apiFetch(`${endpoint}/dispute`, {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ reason }),
        });

        setAsking(false);
        setReason("");
        router.refresh();
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent(page)}`);

          return;
        }

        throw error;
      }
    });
  }

  if (dispute) {
    return (
      <div className="border-caution bg-card space-y-3 rounded-lg border p-4">
        <p className="text-sm font-semibold">
          {dispute.is_open ? "Disputed, and we are looking at it" : "Dispute decided"}
        </p>

        <div className="space-y-1">
          <p className="text-muted-foreground text-xs">
            {viewer === "buyer" ? "What you said" : "What the buyer said"}
            {dispute.opened_at ? `, ${formatDate(dispute.opened_at)}` : null}
          </p>
          <p className="text-sm leading-relaxed whitespace-pre-wrap">{dispute.reason}</p>
        </div>

        {dispute.resolution ? (
          <div className="border-border space-y-1 border-t pt-3">
            <p className="text-sm font-medium">{OUTCOME[dispute.resolution][viewer]}</p>
            <p className="text-muted-foreground text-xs">
              Why we decided it
              {dispute.resolved_at ? `, ${formatDate(dispute.resolved_at)}` : null}
            </p>
            <p className="text-sm leading-relaxed whitespace-pre-wrap">{dispute.resolution_note}</p>
          </div>
        ) : (
          <p className="text-muted-foreground text-xs leading-relaxed">
            The payment is held until we have decided, and the order will not complete on its own in
            the meantime.
          </p>
        )}
      </div>
    );
  }

  if (!canDispute) {
    return null;
  }

  return (
    <div className="space-y-3">
      {asking ? (
        /*
         * The form is the pause. Raising one holds the shop's money and is not
         * something to do by mis-tapping, and it cannot be withdrawn - the
         * platform decides it either way (ADR 0051).
         */
        <form
          onSubmit={raise}
          noValidate
          aria-label="Tell us what went wrong"
          className="border-border bg-muted space-y-3 rounded-lg border p-4"
        >
          <FieldFrame
            label="What went wrong?"
            hint="The shop reads this, and so do we. Say what you expected and what turned up."
            errors={fieldErrors.reason}
          >
            {(control) => (
              <Textarea
                {...control}
                ref={reasonField}
                name="reason"
                rows={4}
                maxLength={2000}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
              />
            )}
          </FieldFrame>

          <p className="text-muted-foreground text-xs leading-relaxed">
            While we look at it the payment stays with us, and the order will not complete on its
            own. You cannot take a dispute back, so say as much as you can now.
          </p>

          <div className="flex flex-wrap gap-2">
            <Button type="submit" size="sm" disabled={pending}>
              {pending ? "Sending..." : "Raise a dispute"}
            </Button>
            <Button variant="ghost" size="sm" disabled={pending} onClick={() => setAsking(false)}>
              Not now
            </Button>
          </div>
        </form>
      ) : (
        <Button variant="secondary" disabled={pending} onClick={() => setAsking(true)}>
          Something went wrong
        </Button>
      )}

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </div>
  );
}
