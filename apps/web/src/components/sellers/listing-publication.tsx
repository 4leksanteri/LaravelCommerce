"use client";

import { useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import type { Product } from "@/lib/api/types";

/**
 * Putting a listing on sale, taking it off, and deleting it.
 *
 * **The button is `can_publish`**, which is ownership and nothing else. Whether
 * the listing may actually go on sale - an approved shop, a category chosen -
 * is a fact about the world rather than about the person, so the API answers it
 * with a 409 and its message is shown as it was sent (ADR 0008). This component
 * knows neither rule.
 *
 * **Deleting asks first and cannot be undone from here.** It is a soft delete,
 * so an order that already references the listing keeps something to point at,
 * but nothing in this application brings one back.
 */
export function ListingPublication({ listing }: { listing: Product }) {
  const router = useRouter();
  const { pending, failure, submit } = useApiSubmit();
  const [asking, setAsking] = useState(false);
  const confirm = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (asking) {
      confirm.current?.focus();
    }
  }, [asking]);

  async function change(method: "POST" | "DELETE") {
    // A refusal is not caught here. `useApiSubmit` classifies it once, and a
    // 409 - an unapproved shop, or no category yet - reaches `failure` as the
    // sentence the API sent.
    await submit(async () => {
      await apiFetch(`/seller/products/${listing.id}/publication`, { method });

      router.refresh();
    });
  }

  async function remove() {
    await submit(async () => {
      await apiFetch(`/seller/products/${listing.id}`, { method: "DELETE" });

      router.push("/seller/listings");
      router.refresh();
    });
  }

  return (
    <div className="space-y-3">
      {listing.status === "published" ? (
        <div className="space-y-2">
          <Button variant="secondary" disabled={pending} onClick={() => change("DELETE")}>
            {pending ? "Working..." : "Take it off sale"}
          </Button>
          <p className="text-muted-foreground text-xs">
            It stays in your catalogue as a draft, and shoppers stop seeing it.
          </p>
        </div>
      ) : listing.can_publish ? (
        <div className="space-y-2">
          <Button disabled={pending} onClick={() => change("POST")}>
            {pending ? "Working..." : "Put it on sale"}
          </Button>
          <p className="text-muted-foreground text-xs">
            Shoppers can find it once it is on sale and your shop is open.
          </p>
        </div>
      ) : null}

      {failure ? <Alert tone="danger">{failure}</Alert> : null}

      <div className="border-border border-t pt-3">
        {asking ? (
          <div className="space-y-3">
            <p className="text-sm leading-relaxed">
              Delete {listing.name}? Shoppers stop seeing it immediately, and this cannot be undone
              here.
            </p>
            <div className="flex flex-wrap gap-2">
              <Button ref={confirm} size="sm" disabled={pending} onClick={remove}>
                {pending ? "Deleting..." : "Yes, delete it"}
              </Button>
              <Button variant="ghost" size="sm" disabled={pending} onClick={() => setAsking(false)}>
                Keep it
              </Button>
            </div>
          </div>
        ) : (
          <Button variant="ghost" size="sm" disabled={pending} onClick={() => setAsking(true)}>
            Delete this listing
          </Button>
        )}
      </div>
    </div>
  );
}
