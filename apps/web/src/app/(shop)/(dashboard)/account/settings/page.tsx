import type { Metadata } from "next";

import { EmailForm } from "@/components/account/email-form";
import { NameForm } from "@/components/account/name-form";
import { PasswordForm } from "@/components/account/password-form";
import { requireUser } from "@/lib/auth/session";

export const metadata: Metadata = {
  title: "Settings",
  robots: { index: false, follow: false },
};

/**
 * The account's name, email address and password (ADR 0034).
 *
 * Three forms rather than one. They are three requests with different
 * consequences: a name changes and nothing else happens, an address has to be
 * confirmed again, and a password signs out everywhere else. One save button
 * over all three would leave somebody guessing which of those they had done.
 */
export default async function AccountSettingsPage() {
  const user = await requireUser("/account/settings");

  return (
    <div className="max-w-2xl space-y-10">
      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">Settings</h1>
        <p className="text-muted-foreground text-sm">
          Your name, your email address and your password.
        </p>
      </header>

      <section aria-labelledby="name-heading" className="space-y-4">
        <h2 id="name-heading" className="font-semibold">
          Your name
        </h2>
        <NameForm name={user.name} />
      </section>

      <section aria-labelledby="email-heading" className="space-y-4">
        <div className="space-y-1">
          <h2 id="email-heading" className="font-semibold">
            Email address
          </h2>
          <p className="text-muted-foreground text-sm">
            Now {user.email}
            {user.email_verified_at ? ", confirmed." : ", not confirmed yet."}
          </p>
        </div>
        <EmailForm />
      </section>

      <section aria-labelledby="password-heading" className="space-y-4">
        <h2 id="password-heading" className="font-semibold">
          Password
        </h2>
        <PasswordForm />
      </section>
    </div>
  );
}
