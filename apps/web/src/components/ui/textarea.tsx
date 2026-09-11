import type { ComponentProps } from "react";

import { cn } from "@/lib/utils";

/**
 * Several lines of text. The input's styling, including `aria-invalid` driving
 * the error state, so the two cannot drift apart. Put it in a `FieldFrame` to
 * get a label and messages.
 */
export function Textarea({ className, ...props }: ComponentProps<"textarea">) {
  return (
    <textarea
      className={cn(
        "border-input bg-card placeholder:text-muted-foreground min-h-24 w-full rounded-md border px-3 py-2 text-sm",
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
