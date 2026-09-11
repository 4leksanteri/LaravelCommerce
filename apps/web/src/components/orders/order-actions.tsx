"use client";

import { useRouter } from "next/navigation";
import { useEffect, useId, useRef, useState } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Order, Resource } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";

/**
 * The three things a buyer can do to their own order.
 *
 * **Each button is the API's answer.** `can_cancel`, `can_complete` and
 * `can_extend_completion` are drawn as they arrive, and nothing here looks at
 * the status to decide. Once a shop has accepted, only the shop may cancel, and
 * that asymmetry is the API's to know (ADR 0012).
 *
 * **Two of them ask first.** Cancelling and confirming arrival are final, and
 * a button that ends an order on one click is one mis-tap from a support
 * ticket. The question replaces the buttons in place and takes the focus, so a
 * keyboard lands on the answer. Asking for more time is not final and does not
 * ask; it says where the date moved to, which is the API's new date rather
 * than one worked out here.
 *
 * **A refusal redraws the page.** A 409 means the order moved while this was
 * open - the shop accepted it, or the deadline passed. The API's message says
 * which, and the page is drawn again from the order as it is now.
 */
type Question = "cancel" | "complete" | null;

export function OrderActions({ order }: { order: Order }) {
  const router = useRouter();
  const { pending, failure, submit } = useApiSubmit();
  const [asking, setAsking] = useState<Question>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const answer = useRef<HTMLButtonElement>(null);
  const questionId = useId();

  useEffect(() => {
    if (asking) {
      answer.current?.focus();
    }
  }, [asking]);

  // The API's address for the order, and the page's. They differ since the
  // orders pages moved under /account (ADR 0033), and mixing them up would
  // send somebody signing back in to a page that does not exist.
  const endpoint = `/orders/${encodeURIComponent(order.reference)}`;
  const page = `/account/orders/${encodeURIComponent(order.reference)}`;

  async function act(action: string, afterwards?: (updated: Order) => void) {
    setNotice(null);

    await submit(async () => {
      try {
        const updated = await apiFetch<Resource<Order>>(`${endpoint}/${action}`, {
          method: "POST",
        });

        setAsking(null);
        afterwards?.(updated.data);
        router.refresh();
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent(page)}`);

          return;
        }

        if (error instanceof ApiError && error.status === 409) {
          setAsking(null);
          router.refresh();
        }

        throw error;
      }
    });
  }

  const allowed = order.can_cancel || order.can_complete || order.can_extend_completion;

  return (
    <div className="space-y-3">
      {asking ? (
        <div
          role="group"
          aria-labelledby={questionId}
          className="border-border bg-muted space-y-3 rounded-lg border p-4"
        >
          <p id={questionId} className="text-sm leading-relaxed">
            {asking === "complete"
              ? "Only confirm once you have checked it over. Completing an order cannot be undone."
              : "Cancel this order? This cannot be undone."}
          </p>
          <div className="flex flex-wrap gap-2">
            <Button
              ref={answer}
              size="sm"
              disabled={pending}
              onClick={() => act(asking === "complete" ? "completion" : "cancellation")}
            >
              {asking === "complete" ? "Yes, it arrived" : "Yes, cancel it"}
            </Button>
            <Button variant="ghost" size="sm" disabled={pending} onClick={() => setAsking(null)}>
              {asking === "complete" ? "Go back" : "Keep it"}
            </Button>
          </div>
        </div>
      ) : allowed ? (
        <div className="flex flex-wrap items-center gap-3">
          {order.can_complete ? (
            <Button disabled={pending} onClick={() => setAsking("complete")}>
              Confirm it arrived
            </Button>
          ) : null}

          {order.can_extend_completion ? (
            <span className="inline-flex flex-wrap items-center gap-2">
              <Button
                variant="secondary"
                disabled={pending}
                onClick={() =>
                  act("completion-extension", (updated) => {
                    if (updated.auto_complete_at) {
                      setNotice(
                        `Given more time: it now completes on its own on ${formatDate(updated.auto_complete_at)}.`,
                      );
                    }
                  })
                }
              >
                It has not arrived yet
              </Button>
              <span className="text-muted-foreground text-xs">
                {order.completion_extensions_left === 1
                  ? "1 extension left"
                  : `${order.completion_extensions_left} extensions left`}
              </span>
            </span>
          ) : null}

          {order.can_cancel ? (
            <Button variant="secondary" disabled={pending} onClick={() => setAsking("cancel")}>
              Cancel order
            </Button>
          ) : null}
        </div>
      ) : null}

      {notice ? <Alert tone="positive">{notice}</Alert> : null}
      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </div>
  );
}
