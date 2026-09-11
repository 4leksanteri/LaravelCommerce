import type { Metadata } from "next";

import { AuthCard } from "@/components/auth/auth-card";
import { ResendVerification } from "@/components/auth/resend-verification";
import { Alert } from "@/components/ui/alert";
import { TextLink } from "@/components/ui/text-link";
import { requireUser } from "@/lib/auth/session";

export const metadata: Metadata = { title: "Check your inbox" };

/**
 * Where registration lands, and where a stale verification link sends people.
 *
 * **An unverified account is not a locked one.** Browsing, filling a basket and
 * applying for a shop are all open; it is checking out that needs a confirmed
 * address, because the receipt and everything about a dispute go to it
 * (ADR 0011). So this page tells somebody what is waiting for them rather than
 * standing between them and the marketplace.
 */
export default async function VerificationSentPage() {
  // Resending needs a session: the endpoint sends to the signed-in account and
  // takes no address, which is what stops it posting mail to strangers.
  const user = await requireUser("/verify-email/sent");

  if (user.email_verified_at) {
    return (
      <AuthCard title="Already confirmed">
        <div className="space-y-4">
          <Alert tone="positive">
            <span className="font-medium">{user.email}</span> is confirmed. There is nothing left to
            do here.
          </Alert>

          <TextLink href="/">Start browsing</TextLink>
        </div>
      </AuthCard>
    );
  }

  return (
    <AuthCard
      title="Check your inbox"
      description={
        <>
          We have sent a link to <span className="text-foreground font-medium">{user.email}</span>.
          Open it to confirm the address.
        </>
      }
    >
      <div className="space-y-4">
        <p className="text-muted-foreground text-sm leading-relaxed">
          You can browse and fill a basket without this. Confirming is needed to check out, because
          your receipt goes to this address.
        </p>

        <ResendVerification />
      </div>
    </AuthCard>
  );
}
