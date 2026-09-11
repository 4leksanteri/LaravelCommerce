import type { Metadata } from "next";
import { redirect } from "next/navigation";

import { AuthCard } from "@/components/auth/auth-card";
import { LoginForm } from "@/components/auth/login-form";
import { TextLink } from "@/components/ui/text-link";
import { safeRedirect } from "@/lib/auth/redirects";
import { currentUser } from "@/lib/auth/session";

export const metadata: Metadata = { title: "Sign in" };

/**
 * `?next=` is where the person was heading before something asked them to sign
 * in. It is sanitised rather than trusted: an unchecked redirect target is how
 * a phishing link borrows the credibility of a real sign-in page.
 */
export default async function LoginPage({ searchParams }: PageProps<"/login">) {
  const { next } = await searchParams;
  const destination = safeRedirect(typeof next === "string" ? next : null);

  // Somebody already signed in has no business on this form, and sending them
  // where they were going is more useful than showing it to them anyway.
  if (await currentUser()) {
    redirect(destination);
  }

  return (
    <AuthCard
      title="Sign in"
      description="Buy from small shops, and pay only when it arrives."
      footer={
        <>
          New here? <TextLink href="/register">Create an account</TextLink>
        </>
      }
    >
      <LoginForm redirectTo={destination} />
    </AuthCard>
  );
}
