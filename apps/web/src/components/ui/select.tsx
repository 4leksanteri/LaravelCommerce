import type { ComponentProps } from "react";

import { cn } from "@/lib/utils";

/**
 * A choice from a short list, as the browser's own select.
 *
 * Native rather than a custom listbox: it is accessible without any work, it
 * opens the phone's own picker, and the lists here are short. The input's
 * styling, so a form of both reads as one. Put it in a `FieldFrame` to get a
 * label and messages.
 */
export function Select({ className, ...props }: ComponentProps<"select">) {
  return (
    <select
      className={cn(
        "border-input bg-card h-10 w-full rounded-md border px-3 py-2 text-sm",
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
