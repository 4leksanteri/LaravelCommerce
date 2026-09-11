import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";

import { ShopApplicationForm } from "@/components/sellers/shop-application-form";
import { Alert } from "@/components/ui/alert";
import { buttonStyles } from "@/components/ui/button";
import { requireUser } from "@/lib/auth/session";
import { readShop } from "@/lib/sellers/shop";

export const metadata: Metadata = { title: "Open a shop" };

/**
 * Applying to sell.
 *
 * **Whether somebody may apply is the API's answer**, `shop_application_blocker`
 * on the signed-in user, as checkout's is on the cart (ADR 0030, ADR 0033). The
 * page does not read `email_verified_at` or a shop's status and decide.
 *
 *   unverified_email   confirm the address first
 *   awaiting_review    already applied: the shop's page says where it stands
 *   already_open       already selling: likewise
 *   null               the form - empty, or filled from a rejected application
 *
 * **One form, not the export's four steps.** Two of those ask for things nothing
 * records - what kind of goods, how many, shipped from where - and the third is
 * payouts, which ADR 0031 puts after approval rather than before it.
 *
 * Outside the account's layout, because until the application is sent there is
 * no shop for that layout's shop section to show.
 */
export default async function SellPage() {
  const user = await requireUser("/sell");
  const blocker = user.shop_application_blocker;

  if (blocker === "awaiting_review" || blocker === "already_open") {
    redirect("/seller");
  }

  // A rejected application, to apply again from. Null for a first one.
  const previous = user.has_shop ? await readShop() : null;

  return (
    <div className="mx-auto w-full max-w-2xl space-y-6 px-4 py-8 sm:py-10">
      <header className="space-y-2">
        <h1 className="text-2xl font-bold tracking-tight">
          {previous ? "Apply again" : "Open a shop"}
        </h1>
        <p className="text-muted-foreground text-sm leading-relaxed">
          Staff read every application before a shop can publish anything, and an account has one
          shop. Once yours is open you can list what you sell and set up how you get paid.
        </p>
      </header>

      {blocker === "unverified_email" ? (
        <div className="space-y-3">
          <Alert tone="danger">Confirm your email address before applying.</Alert>
          <Link href="/verify-email/sent" className={buttonStyles({ size: "sm" })}>
            Confirm your email address
          </Link>
        </div>
      ) : (
        <>
          {previous?.rejection_reason ? (
            <Alert tone="danger">
              Your last application was not approved. The reason given: {previous.rejection_reason}
            </Alert>
          ) : null}

          <ShopApplicationForm
            initial={{
              shopName: previous?.shop_name ?? "",
              description: previous?.description ?? "",
              contactEmail: previous?.contact_email ?? user.email,
            }}
            fixedCurrency={previous?.currency}
          />
        </>
      )}
    </div>
  );
}
