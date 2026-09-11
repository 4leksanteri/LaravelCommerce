"use client";

import { useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";

import { AddressForm } from "@/components/checkout/address-form";
import { AddressLines } from "@/components/checkout/address-lines";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Address } from "@/lib/api/types";

/**
 * The address book: every saved address, with a way to change or remove each
 * and to add another.
 *
 * **One thing is edited at a time.** While an entry or a new address is open,
 * the other buttons wait, so there is never a second form whose unsaved
 * changes the first one's save would redraw away.
 *
 * **Removing asks first**, and says what it does not do: an order already sent
 * to the address kept its own copy of it (ADR 0021), so nothing about a past
 * parcel changes. After every change the page is drawn again from the API.
 */
type Editing = number | "new" | null;

export function SavedAddresses({ addresses }: { addresses: Address[] }) {
  const router = useRouter();
  const [editing, setEditing] = useState<Editing>(addresses.length === 0 ? "new" : null);

  const saved = () => {
    setEditing(null);
    router.refresh();
  };

  return (
    <div className="space-y-4">
      {addresses.length > 0 ? (
        <ul aria-label="Your addresses" className="space-y-3">
          {addresses.map((address) =>
            editing === address.id ? (
              <li key={address.id}>
                <AddressForm address={address} onSaved={saved} onCancel={() => setEditing(null)} />
              </li>
            ) : (
              <SavedAddress
                key={address.id}
                address={address}
                busy={editing !== null}
                onEdit={() => setEditing(address.id)}
              />
            ),
          )}
        </ul>
      ) : (
        <p className="text-muted-foreground text-sm">
          No addresses yet. Add one here, or at checkout.
        </p>
      )}

      {editing === "new" ? (
        <AddressForm
          onSaved={saved}
          onCancel={addresses.length > 0 ? () => setEditing(null) : undefined}
        />
      ) : (
        <Button variant="secondary" disabled={editing !== null} onClick={() => setEditing("new")}>
          Add an address
        </Button>
      )}
    </div>
  );
}

function SavedAddress({
  address,
  busy,
  onEdit,
}: {
  address: Address;
  busy: boolean;
  onEdit: () => void;
}) {
  const router = useRouter();
  const { pending, failure, submit } = useApiSubmit();
  const [asking, setAsking] = useState(false);
  const answer = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (asking) {
      answer.current?.focus();
    }
  }, [asking]);

  // Named in full, because "Edit" and "Remove" are on every entry and a screen
  // reader listing the buttons would otherwise hear the same two words over and
  // over. The visible word leads, so a voice command saying it still works.
  const which = `${address.name}, ${address.line1}`;

  async function remove() {
    await submit(async () => {
      try {
        await apiFetch<void>(`/addresses/${address.id}`, { method: "DELETE" });
      } catch (error) {
        // Already removed, in another tab. That is the state wanted.
        if (!(error instanceof ApiError && error.status === 404)) {
          throw error;
        }
      }

      router.refresh();
    });
  }

  return (
    <li className="bg-card border-border space-y-3 rounded-lg border p-4">
      <AddressLines address={address} />

      {asking ? (
        <div
          role="group"
          aria-label={`Remove ${which}?`}
          className="border-border bg-muted space-y-3 rounded-md border p-3"
        >
          <p className="text-sm leading-relaxed">
            Remove this address? Orders already sent to it keep their own copy.
          </p>
          <div className="flex flex-wrap gap-2">
            <Button ref={answer} size="sm" disabled={pending} onClick={remove}>
              Yes, remove it
            </Button>
            <Button variant="ghost" size="sm" disabled={pending} onClick={() => setAsking(false)}>
              Keep it
            </Button>
          </div>
        </div>
      ) : (
        <div className="flex flex-wrap gap-2">
          <Button
            variant="secondary"
            size="sm"
            disabled={busy}
            onClick={onEdit}
            aria-label={`Edit ${which}`}
          >
            Edit
          </Button>
          <Button
            variant="ghost"
            size="sm"
            disabled={busy}
            onClick={() => setAsking(true)}
            aria-label={`Remove ${which}`}
          >
            Remove
          </Button>
        </div>
      )}

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </li>
  );
}
