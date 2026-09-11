import type { Metadata } from "next";

import { AuthCard } from "@/components/auth/auth-card";
import { VerifyEmail } from "@/components/auth/verify-email";
import { Alert } from "@/components/ui/alert";
import { TextLink } from "@/components/ui/text-link";

export const metadata: Metadata = { title: "Confirm your email" };

/** The four values the API put in the link, all of them required. */
function read(value: string | string[] | undefined): string | null {
  return typeof value === "string" && value !== "" ? value : null;
}

export default async function VerifyEmailPage({ searchParams }: PageProps<"/verify-email">) {
  const query = await searchParams;

  const id = read(query.id);
  const hash = read(query.hash);
  const expires = read(query.expires);
  const signature = read(query.signature);

  if (!id || !hash || !expires || !signature) {
    return (
      <AuthCard title="This link is incomplete">
        <div className="space-y-4">
          <Alert tone="danger">
            Part of the link is missing, so there is nothing to check. Open it from the email rather
            than retyping it.
          </Alert>

          <p className="text-muted-foreground text-sm">
            <TextLink href="/verify-email/sent">Send another link</TextLink> if you no longer have
            the email.
          </p>
        </div>
      </AuthCard>
    );
  }

  return (
    <AuthCard title="Confirming your email">
      <VerifyEmail id={id} hash={hash} expires={expires} signature={signature} />
    </AuthCard>
  );
}
