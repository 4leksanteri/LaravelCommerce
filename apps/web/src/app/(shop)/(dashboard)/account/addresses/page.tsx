import type { Metadata } from "next";

import { SavedAddresses } from "@/components/account/saved-addresses";
import { serverFetch } from "@/lib/api/server";
import type { AddressBook, Resource } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";

export const metadata: Metadata = {
  title: "Addresses",
  robots: { index: false, follow: false },
};

/**
 * Where parcels go: the address book checkout chooses from.
 *
 * The API has edited and removed addresses since ADR 0021; this is their page
 * (ADR 0034). Changing an address changes where the next parcel goes and
 * nothing about an earlier one, because every order froze its own copy.
 */
export default async function AddressesPage() {
  await requireUser("/account/addresses");

  const addresses = (await serverFetch<Resource<AddressBook>>("/addresses")).data;

  return (
    <div className="max-w-2xl space-y-6">
      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">Addresses</h1>
        <p className="text-muted-foreground text-sm leading-relaxed">
          Where your parcels go. Checkout offers these, newest first. Changing one changes where the
          next parcel goes, never where an earlier one went.
        </p>
      </header>

      <SavedAddresses addresses={addresses} />
    </div>
  );
}
