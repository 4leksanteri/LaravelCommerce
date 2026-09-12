import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import type { ReactNode } from "react";

import { PayoutDetailsForm } from "@/components/sellers/payout-details-form";
import { PayoutIdentityDocument } from "@/components/sellers/payout-identity-document";
import { PayoutOpenForm } from "@/components/sellers/payout-open-form";
import { PayoutStatusBadge } from "@/components/sellers/payout-status-badge";
import { Alert } from "@/components/ui/alert";
import { serverFetch } from "@/lib/api/server";
import type { PayoutAccount, Resource } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";
import { formatDate } from "@/lib/dates";
import { PAYOUT_FIELDS } from "@/lib/sellers/payouts";
import { readShop } from "@/lib/sellers/shop";

export const metadata: Metadata = {
  title: "Payouts",
  robots: { index: false, follow: false },
};

/**
 * How the shop gets paid: opening the account, and whatever Stripe still wants
 * before money can reach it (ADR 0039).
 *
 * **Every state on this page is the API's answer.** `status` is derived from
 * the copy of what Stripe last said, `due` is what is outstanding, `can_open`
 * is whether an account may be opened at all, and `errors` is what Stripe could
 * not verify. Nothing here reads a shop's approval or a requirement list to
 * decide what to draw (ADR 0031).
 *
 * **What this page never shows is money.** No charge has been taken anywhere in
 * this application, so there is no balance and no payout to list - only whether
 * the account that will receive one is ready. Payments are ADR 0015, and are
 * not built.
 */
export default async function PayoutsPage() {
  await requireUser("/seller/payouts");

  const shop = await readShop();

  if (!shop) {
    redirect("/sell");
  }

  const { data: account } = await serverFetch<Resource<PayoutAccount>>("/seller/payout-account");

  return (
    <div className="max-w-2xl space-y-8">
      <header className="space-y-2">
        <h1 className="text-2xl font-bold tracking-tight">Payouts</h1>
        <PayoutStatusBadge status={account.status} className="text-sm" />
      </header>

      <Standing account={account} />

      {account.status === "not_started" ? (
        account.can_open ? (
          <section aria-labelledby="open-heading" className="space-y-4">
            <h2 id="open-heading" className="font-semibold">
              Open the account
            </h2>
            <PayoutOpenForm countries={account.countries} />
          </section>
        ) : null
      ) : (
        <>
          {account.due.length > 0 ? (
            <section aria-labelledby="details-heading" className="space-y-4">
              <h2 id="details-heading" className="font-semibold">
                What Stripe still needs
              </h2>
              {account.due_by ? (
                <p className="text-muted-foreground text-sm">
                  Send it by {formatDate(account.due_by)}, or Stripe stops payouts until it arrives.
                </p>
              ) : null}
              <PayoutDetailsForm due={account.due} />
            </section>
          ) : null}

          {account.due.includes("identity_document") ? (
            <section aria-labelledby="document-heading" className="space-y-4">
              <h2 id="document-heading" className="font-semibold">
                {PAYOUT_FIELDS.identity_document.label}
              </h2>
              <p className="text-muted-foreground text-sm">
                {PAYOUT_FIELDS.identity_document.hint} It goes straight to Stripe and is not kept
                here.
              </p>
              <PayoutIdentityDocument />
            </section>
          ) : null}

          <Problems account={account} />

          <section aria-labelledby="account-heading" className="space-y-3">
            <h2 id="account-heading" className="font-semibold">
              The account
            </h2>
            <dl className="bg-card border-border divide-border divide-y rounded-lg border">
              <Detail term="Country">{account.country ?? "Not set"}</Detail>
              <Detail term="Paid into">
                {account.bank_account_last4 ? (
                  <span className="font-mono">****{account.bank_account_last4}</span>
                ) : (
                  <span className="text-muted-foreground">No bank account yet</span>
                )}
              </Detail>
            </dl>
          </section>
        </>
      )}
    </div>
  );
}

/** What the status means for the person reading it. Exhaustive over the API's cases. */
function Standing({ account }: { account: PayoutAccount }) {
  switch (account.status) {
    case "not_started":
      return account.can_open ? (
        <Alert tone="info">
          Your shop is open, so it can be paid. Stripe holds the account and asks for what the law
          requires; this page collects it and keeps none of it.
        </Alert>
      ) : (
        <div className="space-y-3">
          <Alert tone="info">
            A payout account can be opened once staff have approved your shop.
          </Alert>
          <p className="text-sm">
            <Link href="/seller" className="text-primary font-medium hover:underline">
              Where your shop stands
            </Link>
          </p>
        </div>
      );

    case "action_required":
      return (
        <Alert tone="caution">
          Stripe needs more before it will pay you. What is missing is below.
        </Alert>
      );

    case "in_review":
      return (
        <Alert tone="info">
          Stripe has everything it asked for and is checking it. Nothing to do but wait.
        </Alert>
      );

    case "active":
      return (
        <Alert tone="positive">
          The account is ready. Nothing is paid out yet, because no payment has been taken anywhere
          on this marketplace.
        </Alert>
      );

    case "rejected":
      return (
        <Alert tone="danger">
          Stripe will not accept this account. Its reason is below where it gave one, and Stripe
          support is the only way on from here.
        </Alert>
      );

    default: {
      const unhandled: never = account.status;

      return unhandled;
    }
  }
}

/**
 * What Stripe could not verify, and what it is asking for that this page cannot
 * yet collect. Both are named rather than hidden: a seller who cannot get paid
 * should be able to see why, even when the answer is that this application has
 * not caught up with Stripe (ADR 0031).
 */
function Problems({ account }: { account: PayoutAccount }) {
  if (account.errors.length === 0 && account.unsupported.length === 0) {
    return null;
  }

  return (
    <section aria-labelledby="problems-heading" className="space-y-3">
      <h2 id="problems-heading" className="font-semibold">
        What Stripe could not accept
      </h2>

      {account.errors.length > 0 ? (
        <ul className="space-y-2">
          {account.errors.map((problem) => (
            <li key={problem.requirement} className="text-sm">
              <span className="font-medium">
                {problem.field ? PAYOUT_FIELDS[problem.field].label : problem.requirement}:
              </span>{" "}
              {problem.reason}
            </li>
          ))}
        </ul>
      ) : null}

      {account.unsupported.length > 0 ? (
        <Alert tone="caution">
          Stripe is also asking for something this page cannot collect yet:{" "}
          {account.unsupported.join(", ")}. Nothing you can do here will clear it.
        </Alert>
      ) : null}
    </section>
  );
}

function Detail({ term, children }: { term: string; children: ReactNode }) {
  return (
    <div className="grid gap-1 px-4 py-3 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-4">
      <dt className="text-muted-foreground text-sm">{term}</dt>
      <dd className="text-sm break-words">{children}</dd>
    </div>
  );
}
