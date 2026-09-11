import type { Metadata } from "next";

import { AuthCard } from "@/components/auth/auth-card";
import { ResetPasswordForm } from "@/components/auth/reset-password-form";
import { Alert } from "@/components/ui/alert";
import { TextLink } from "@/components/ui/text-link";

export const metadata: Metadata = { title: "Choose a new password" };

/**
 * The page the reset email links to, carrying `token` and `email`.
 *
 * A visit with either missing is somebody who typed the address or whose mail
 * client mangled the link. That is answered here rather than by rendering a
 * form that cannot succeed - a form that submits and always fails reads as the
 * password being wrong.
 */
export default async function ResetPasswordPage({ searchParams }: PageProps<"/reset-password">) {
  const { token, email } = await searchParams;

  if (typeof token !== "string" || typeof email !== "string" || !token || !email) {
    return (
      <AuthCard title="This link is incomplete">
        <div className="space-y-4">
          <Alert tone="danger">
            The address you arrived at is missing part of the link. Mail clients sometimes break
            long URLs across lines.
          </Alert>

          <p className="text-muted-foreground text-sm">
            <TextLink href="/forgot-password">Ask for a new link</TextLink> and open it from the
            email directly.
          </p>
        </div>
      </AuthCard>
    );
  }

  return (
    <AuthCard title="Choose a new password" description="This link can only be used once.">
      <ResetPasswordForm token={token} email={email} />
    </AuthCard>
  );
}
