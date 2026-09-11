import type { Metadata } from "next";
import { redirect } from "next/navigation";

import { ShopDetailsForm } from "@/components/sellers/shop-details-form";
import { Alert } from "@/components/ui/alert";
import { requireUser } from "@/lib/auth/session";
import { readShop } from "@/lib/sellers/shop";

export const metadata: Metadata = {
  title: "Shop settings",
  robots: { index: false, follow: false },
};

/**
 * What shoppers read about the shop, and where it is contacted.
 *
 * Three things can change, and they are `UpdateShopRequest`'s three. Two more
 * are shown and cannot: the currency, fixed when the shop applied because
 * everything it sells is priced in it (ADR 0004, ADR 0007), and its address in
 * links, which does not move when the name does.
 *
 * The form is drawn from `can_edit`, the API's answer. For the owner it is
 * always yes today, and it is still the thing to ask.
 */
export default async function ShopSettingsPage() {
  await requireUser("/seller/settings");

  const shop = await readShop();

  if (!shop) {
    redirect("/sell");
  }

  return (
    <div className="max-w-2xl space-y-8">
      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">Shop settings</h1>
        <p className="text-muted-foreground text-sm">
          What shoppers read about your shop, and where it is contacted.
        </p>
      </header>

      {shop.can_edit ? (
        <ShopDetailsForm shop={shop} />
      ) : (
        <Alert>Only the shop&apos;s owner can change these.</Alert>
      )}

      <section aria-labelledby="fixed-heading" className="space-y-3">
        <h2 id="fixed-heading" className="font-semibold">
          Fixed when you applied
        </h2>
        <dl className="bg-card border-border divide-border divide-y rounded-lg border">
          <div className="space-y-1 px-4 py-3">
            <dt className="text-sm font-medium">Currency</dt>
            <dd className="text-muted-foreground text-sm leading-relaxed">
              {shop.currency}. Everything the shop sells is priced in it, so it cannot change
              without changing what earlier buyers paid.
            </dd>
          </div>
          <div className="space-y-1 px-4 py-3">
            <dt className="text-sm font-medium">Address in links</dt>
            <dd className="text-muted-foreground text-sm leading-relaxed">
              <code className="text-foreground font-mono">{shop.slug}</code>. It stays the same when
              the name changes, so links to the shop keep working.
            </dd>
          </div>
        </dl>
      </section>
    </div>
  );
}
