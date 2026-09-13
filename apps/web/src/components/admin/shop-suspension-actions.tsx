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
import type { Shop } from "@/lib/api/types";

/**
 * Stopping a shop that is trading, and letting it start again (ADR 0052).
 *
 * **A sibling of `ShopReviewActions` rather than part of it.** They are two
 * different decisions with two different permissions - `can_review` settles an
 * application, `can_suspend` acts on a business already running - and one
 * component holding four actions behind two gates is where the wrong button
 * gets drawn.
 *
 * **Suspending asks for the reason, and that form is the pause.** It stops
 * somebody's livelihood, the shop is sent what is written, and the API requires
 * it. Reinstating is one click: it is the reversible direction, and asking "are
 * you sure" before the benign thing is how people learn to click through
 * questions without reading them - which is `ShopReviewActions`' own argument.
 *
 * A 409 means the shop moved while this was open, usually another member of
 * staff. The API's message is shown and the queue is drawn again.
 */
export function ShopSuspensionActions({ shop }: { shop: Shop }) {
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

  async function act(method: "POST" | "DELETE", body?: object) {
    await submit(async () => {
      try {
        await apiFetch(`/admin/sellers/${shop.id}/suspension`, {
          method,
          ...(body
            ? { headers: { "content-type": "application/json" }, body: JSON.stringify(body) }
            : {}),
        });

        setAsking(false);
        setReason("");
        router.refresh();
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent("/admin/shops")}`);

          return;
        }

        // Somebody else suspended or reinstated it first.
        if (error instanceof ApiError && error.status === 409) {
          setAsking(false);
          router.refresh();
        }

        throw error;
      }
    });
  }

  function suspend(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    return act("POST", { reason });
  }

  if (shop.status === "suspended") {
    return (
      <div className="space-y-3">
        <Button size="sm" disabled={pending} onClick={() => act("DELETE")}>
          {pending ? "Letting it trade..." : "Let it trade again"}
        </Button>
        <p className="text-muted-foreground text-xs leading-relaxed">
          Its listings go back on sale exactly as they were.
        </p>

        {failure ? <Alert tone="danger">{failure}</Alert> : null}
      </div>
    );
  }

  return (
    <div className="space-y-3">
      {asking ? (
        <form
          onSubmit={suspend}
          noValidate
          aria-label={`Suspend ${shop.shop_name}`}
          className="border-border bg-muted space-y-3 rounded-lg border p-4"
        >
          <FieldFrame
            label="Why are you suspending it?"
            hint="The shop reads this, and it is the only thing it has to go on."
            errors={fieldErrors.reason}
          >
            {(control) => (
              <Textarea
                {...control}
                ref={reasonField}
                name="reason"
                rows={3}
                required
                maxLength={2000}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
              />
            )}
          </FieldFrame>

          <p className="text-muted-foreground text-xs leading-relaxed">
            The shop leaves the storefront at once and can publish nothing new. Orders it has
            already taken are not cancelled: it still owes those, and buyers can still confirm they
            arrived.
          </p>

          <div className="flex flex-wrap gap-2">
            <Button type="submit" size="sm" disabled={pending}>
              {pending ? "Suspending..." : "Suspend the shop"}
            </Button>
            <Button variant="ghost" size="sm" disabled={pending} onClick={() => setAsking(false)}>
              Leave it trading
            </Button>
          </div>
        </form>
      ) : (
        <Button variant="secondary" size="sm" disabled={pending} onClick={() => setAsking(true)}>
          Suspend this shop
        </Button>
      )}

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </div>
  );
}
