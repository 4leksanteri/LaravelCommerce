import { cva, type VariantProps } from "class-variance-authority";
import type { ComponentProps } from "react";

import { cn } from "@/lib/utils";

/**
 * The button.
 *
 * Extracted from the auth screens rather than designed in advance (ADR 0019),
 * so every variant below is on a screen that exists. There is no `destructive`
 * yet because nothing destructive has been built; it arrives with the first
 * thing that deletes something.
 *
 * `primary` is blue and deliberately loud. This is a marketplace with escrow
 * and the control that commits money should be unmistakable rather than
 * tasteful - which makes it the wrong default for anything that does not
 * commit, so `secondary` and `ghost` exist to keep it rare.
 *
 * No `asChild`. shadcn's version takes a Radix Slot so a link can wear a
 * button's clothes; nothing here needs that, and a dependency added for a use
 * that has not arrived is architecture in anticipation. `buttonStyles` is
 * exported so a link can borrow the classes in the meantime.
 */
export const buttonStyles = cva(
  cn(
    "inline-flex items-center justify-center gap-2 rounded-md text-sm font-medium whitespace-nowrap",
    "transition-colors outline-none",
    "focus-visible:ring-ring focus-visible:ring-2 focus-visible:ring-offset-2",
    "focus-visible:ring-offset-background",
    // A disabled submit is the normal state of a form that is mid-flight, so
    // it has to read as "working" rather than as "broken".
    "disabled:pointer-events-none disabled:opacity-60",
  ),
  {
    variants: {
      variant: {
        primary: "bg-primary text-primary-foreground hover:bg-primary/90",
        secondary: "bg-secondary text-secondary-foreground hover:bg-secondary/80",
        ghost: "hover:bg-accent hover:text-accent-foreground text-foreground",
      },
      size: {
        default: "h-10 px-4 py-2",
        sm: "h-9 px-3",
        // Auth forms submit with a full-width button: the form is the only
        // thing on the page and the action is the only thing to do on it.
        block: "h-10 w-full px-4 py-2",
      },
    },
    defaultVariants: { variant: "primary", size: "default" },
  },
);

export type ButtonProps = ComponentProps<"button"> & VariantProps<typeof buttonStyles>;

export function Button({ className, variant, size, type = "button", ...props }: ButtonProps) {
  return (
    <button type={type} className={cn(buttonStyles({ variant, size }), className)} {...props} />
  );
}
