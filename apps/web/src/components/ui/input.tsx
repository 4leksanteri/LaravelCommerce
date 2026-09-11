import type { ComponentProps } from "react";

import { cn } from "@/lib/utils";

/**
 * A text input.
 *
 * `aria-invalid` drives the error styling rather than a prop of our own. The
 * attribute has to be set anyway for a screen reader to announce the field as
 * invalid, so styling from it means the two cannot disagree - a field cannot
 * look wrong while reading as fine, or the reverse.
 */
export function Input({ className, type = "text", ...props }: ComponentProps<"input">) {
  return (
    <input
      type={type}
      className={cn(
        "border-input bg-card placeholder:text-muted-foreground h-10 w-full rounded-md border px-3 py-2 text-sm",
        "transition-colors outline-none",
        "focus-visible:border-ring focus-visible:ring-ring/30 focus-visible:ring-2",
        "disabled:cursor-not-allowed disabled:opacity-60",
        "aria-invalid:border-destructive aria-invalid:focus-visible:ring-destructive/30",
        className,
      )}
      {...props}
    />
  );
}
