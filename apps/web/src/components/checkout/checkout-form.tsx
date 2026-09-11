"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";

import { AddressForm } from "@/components/checkout/address-form";
import { AddressLines } from "@/components/checkout/address-lines";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Address, PlacedOrders } from "@/lib/api/types";
import { loadFresh } from "@/lib/navigation";

/**
 * Choosing where it goes, and placing the orders.
 *
 * **One address for the whole basket.** Every order a checkout creates freezes
 * the same destination (ADR 0021); a basket that could ship to three places is
 * a different product. The newest address is chosen to start with, which the
 * API's newest-first order makes a reasonable guess and not a decision.
 *
 * **What goes to the API is the address id and nothing else.** The cart, the
 * prices and the totals are read on the server under lock (ADR 0011); nothing
 * this component knows contributes a figure to what anybody is charged.
 *
 * **A refusal redraws the page.** A 409 means the basket moved between drawing
 * this and pressing the button - something sold out, or another tab emptied it.
 * The page is redrawn, and the cart's own answer (`checkout_blocker`) then says
 * what is in the way, in one place rather than two.
 *
 * **Success is a full page load into the confirmation**, which has an address
 * of its own so the orders survive a reload. Not a client-side navigation: that
 * keeps the layout as it was drawn, and the end-to-end test caught the result -
 * a confirmation under a header still counting two items in an empty basket.
 * `loadFresh` says why, and names the other moment that needed the same.
 */
export function CheckoutForm({ addresses }: { addresses: Address[] }) {
  const router = useRouter();
  const { pending, failure, submit } = useApiSubmit();

  const [chosenId, setChosenId] = useState<number | null>(addresses[0]?.id ?? null);
  const [adding, setAdding] = useState(addresses.length === 0);
  const [notice, setNotice] = useState<string | null>(null);

  // An address removed in another tab disappears from the list on the next
  // redraw; the choice falls back to one that still exists.
  const chosen = addresses.find((address) => address.id === chosenId) ?? addresses[0] ?? null;

  async function placeOrders() {
    if (!chosen) {
      return;
    }

    setNotice(null);

    await submit(async () => {
      try {
        const placed = await apiFetch<{ data: PlacedOrders }>("/checkout", {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ address_id: chosen.id }),
        });

        const references = placed.data.map((order) => order.reference).join(",");

        loadFresh(`/checkout/placed?orders=${encodeURIComponent(references)}`);
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent("/checkout")}`);

          return;
        }

        if (error instanceof ApiError && error.status === 409) {
          router.refresh();

          return;
        }

        if (error instanceof ApiError && error.status === 404) {
          setNotice("That address is no longer in your address book. Choose another.");
          router.refresh();

          return;
        }

        throw error;
      }
    });
  }

  return (
    <div className="space-y-6">
      <section aria-labelledby="deliver-to" className="space-y-3">
        <h2 id="deliver-to" className="font-semibold">
          Deliver to
        </h2>

        {addresses.length > 0 ? (
          <fieldset className="space-y-2">
            <legend className="sr-only">Choose an address</legend>
            {addresses.map((address) => (
              <label
                key={address.id}
                className="bg-card border-border has-checked:border-primary has-checked:bg-accent has-focus-visible:ring-ring flex cursor-pointer gap-3 rounded-lg border p-4 has-focus-visible:ring-2"
              >
                <input
                  type="radio"
                  name="address"
                  value={address.id}
                  checked={chosen?.id === address.id}
                  onChange={() => setChosenId(address.id)}
                  className="accent-primary mt-1"
                />
                <AddressLines address={address} />
              </label>
            ))}
          </fieldset>
        ) : null}

        {adding ? (
          <AddressForm
            onCreated={(created) => {
              setChosenId(created.id);
              setAdding(false);
              // The list comes from the server; ask it again so the new entry
              // is drawn from the API's copy rather than from ours.
              router.refresh();
            }}
            onCancel={addresses.length > 0 ? () => setAdding(false) : undefined}
          />
        ) : (
          <Button type="button" variant="ghost" size="sm" onClick={() => setAdding(true)}>
            Add a new address
          </Button>
        )}
      </section>

      {notice ? <Alert tone="danger">{notice}</Alert> : null}
      {failure ? <Alert tone="danger">{failure}</Alert> : null}

      <div className="space-y-3">
        <Button
          type="button"
          size="block"
          onClick={placeOrders}
          disabled={pending || !chosen || adding}
        >
          {pending ? "Placing your orders..." : "Place orders"}
        </Button>
        {/*
         * Said plainly, because everything else on this site talks about
         * payments being held. Orders are placed and move through their states
         * for real; taking money is not built yet (ADR 0015).
         */}
        <p className="text-muted-foreground text-xs leading-relaxed">
          This places one order with each shop. No card is charged: taking payment is not built yet.
        </p>
      </div>
    </div>
  );
}
