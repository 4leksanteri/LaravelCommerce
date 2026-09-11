import type { Metadata } from "next";

import { AuthCard } from "@/components/auth/auth-card";
import { ForgotPasswordForm } from "@/components/auth/forgot-password-form";
import { TextLink } from "@/components/ui/text-link";

export const metadata: Metadata = { title: "Reset your password" };

/**
 * Not guarded by a session check, deliberately. Somebody signed in on one
 * device may well be resetting the password for a reason - it is the thing to
 * do when a device has been lost - and bouncing them home would be in the way.
 */
export default function ForgotPasswordPage() {
  return (
    <AuthCard
      title="Reset your password"
      description="Tell us the address on the account and we will send a link to set a new password."
      footer={
        <>
          Remembered it? <TextLink href="/login">Sign in</TextLink>
        </>
      }
    >
      <ForgotPasswordForm />
    </AuthCard>
  );
}
