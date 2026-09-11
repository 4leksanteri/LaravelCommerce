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
import type { SellerOrder } from "@/lib/api/types";

/**
 * What a shop can do to an order: accept it, mark it sent, or call it off.
 *
 * **Each button is the API's answer.** `can_accept`, `can_ship` and
 * `can_cancel` are drawn as they arrive; nothing here reads the status to
 * decide (ADR 0012).
 *
 * **Accepting and sending do not ask first.** They are what a shop does to
 * every order, many times a day, and each is exactly what the buyer is waiting
 * for. Asking "are you sure" before the ordinary thing teaches people to click
 * through questions without reading them.
 *
 * **Cancelling asks for the reason, and that is the confirmation.** The buyer
 * reads it on their order and in the mail that tells them (ADR 0035), so the
 * API requires it, and the form that collects it is also the pause before
 * something that cannot be undone.
 *
 * A 409 means the order moved while this was open - the buyer cancelled it, or
 * its deadline passed - so the API's message is shown and the page is drawn
 * again from the order as it now is.
 */
export function ShopOrderActions({ order }: { order: SellerOrder }) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState("");
  const reasonField = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    if (cancelling) {
      reasonField.current?.focus();
    }
  }, [cancelling]);

  // The API's address for the order, and the page's. They are the same string
  // today; they are named apart because on the buyer's side they stopped being
  // (ADR 0033), and the sign-in page must send somebody back to a page.
  const endpoint = `/seller/orders/${encodeURIComponent(order.reference)}`;
  const page = `/seller/orders/${encodeURIComponent(order.reference)}`;

  async function act(action: "acceptance" | "shipment" | "cancellation", body?: object) {
    await submit(async () => {
      try {
        await apiFetch(`${endpoint}/${action}`, {
          method: "POST",
          ...(body
            ? { headers: { "content-type": "application/json" }, body: JSON.stringify(body) }
            : {}),
        });

        setCancelling(false);
        setReason("");
        router.refresh();
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent(page)}`);

          return;
        }

        if (error instanceof ApiError && error.status === 409) {
          setCancelling(false);
          router.refresh();
        }

        throw error;
      }
    });
  }

  function cancel(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    return act("cancellation", { reason });
  }

  const allowed = order.can_accept || order.can_ship || order.can_cancel;

  return (
    <div className="space-y-3">
      {cancelling ? (
        <form
          onSubmit={cancel}
          noValidate
          aria-label="Cancel the order"
          className="border-border bg-muted space-y-3 rounded-lg border p-4"
        >
          <FieldFrame
            label="Why are you cancelling?"
            hint="The buyer reads this, on their order and in the email that tells them."
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
              {pending ? "Cancelling..." : "Cancel the order"}
            </Button>
            <Button
              variant="ghost"
              size="sm"
              disabled={pending}
              onClick={() => setCancelling(false)}
            >
              Keep it
            </Button>
          </div>
        </form>
      ) : allowed ? (
        <div className="space-y-2">
          <div className="flex flex-wrap items-center gap-3">
            {order.can_accept ? (
              <Button disabled={pending} onClick={() => act("acceptance")}>
                {pending ? "Accepting..." : "Accept order"}
              </Button>
            ) : null}

            {order.can_ship ? (
              <Button disabled={pending} onClick={() => act("shipment")}>
                {pending ? "Marking it sent..." : "Mark as sent"}
              </Button>
            ) : null}

            {order.can_cancel ? (
              <Button variant="secondary" disabled={pending} onClick={() => setCancelling(true)}>
                Cancel order
              </Button>
            ) : null}
          </div>

          {order.can_accept ? (
            <p className="text-muted-foreground text-xs">
              Accepting tells the buyer you will send it. After that, only you can cancel it.
            </p>
          ) : null}
          {order.can_ship ? (
            <p className="text-muted-foreground text-xs">
              Marking it sent tells the buyer it is on its way.
            </p>
          ) : null}
        </div>
      ) : null}

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </div>
  );
}
