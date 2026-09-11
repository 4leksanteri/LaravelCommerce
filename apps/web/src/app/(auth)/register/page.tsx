import type { Metadata } from "next";
import { redirect } from "next/navigation";

import { AuthCard } from "@/components/auth/auth-card";
import { RegisterForm } from "@/components/auth/register-form";
import { TextLink } from "@/components/ui/text-link";
import { currentUser } from "@/lib/auth/session";

export const metadata: Metadata = { title: "Create an account" };

/**
 * One account, whether somebody is here to buy or to sell.
 *
 * There is no "sell with us" variant of this form and there will not be:
 * selling is not a role, it is having a shop, and a shop is applied for from an
 * account that already exists (ADR 0007).
 */
export default async function RegisterPage() {
  if (await currentUser()) {
    redirect("/");
  }

  return (
    <AuthCard
      title="Create an account"
      description="One account to buy with, and to open a shop with later."
      footer={
        <>
          Already have one? <TextLink href="/login">Sign in</TextLink>
        </>
      }
    >
      <RegisterForm />
    </AuthCard>
  );
}
