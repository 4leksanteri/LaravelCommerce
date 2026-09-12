import { cva, type VariantProps } from "class-variance-authority";
import type { ComponentProps } from "react";

import { cn } from "@/lib/utils";

/**
 * A message about the form as a whole, rather than about one field.
 *
 * Field-level messages belong beside their field (see `Field`); this is for the
 * things that have no field - a wrong password, a link that has expired, a
 * confirmation that an email is on its way.
 *
 * `role` is chosen by tone rather than fixed. An `alert` role interrupts a
 * screen reader immediately, which is right for a refusal and wrong for "check
 * your inbox": announcing good news over whatever somebody was reading is the
 * accessibility equivalent of a modal.
 */
const alertStyles = cva("rounded-md border px-3 py-2.5 text-sm", {
  variants: {
    tone: {
      danger: "border-destructive/30 bg-destructive/5 text-destructive",
      positive: "border-positive/30 bg-positive/5 text-positive",
      // Something is waiting on the reader and nothing has gone wrong: Stripe
      // asking a seller for another detail before it will pay them (ADR 0039).
      // `info` understates that and `danger` would announce it over whatever
      // they were reading, since only danger takes the alert role.
      caution: "border-caution/30 bg-caution/5 text-caution",
      info: "border-border bg-muted text-muted-foreground",
    },
  },
  defaultVariants: { tone: "info" },
});

export type AlertProps = ComponentProps<"div"> & VariantProps<typeof alertStyles>;

export function Alert({ className, tone = "info", ...props }: AlertProps) {
  return (
    <div
      role={tone === "danger" ? "alert" : "status"}
      className={cn(alertStyles({ tone }), className)}
      {...props}
    />
  );
}
