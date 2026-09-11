import Link from "next/link";
import type { ComponentProps } from "react";

import { cn } from "@/lib/utils";

/**
 * A link in running text.
 *
 * Thin, and it earns its place by owning the focus ring. An outline that
 * appears on five screens and is drawn slightly differently on one of them is
 * the sort of thing nobody notices until somebody navigating by keyboard does.
 *
 * Underlined rather than colour-only: a link identified by colour alone is one
 * that is not identified at all for anybody who cannot see the difference.
 */
export function TextLink({ className, ...props }: ComponentProps<typeof Link>) {
  return (
    <Link
      className={cn(
        "text-primary rounded-sm font-medium underline underline-offset-4",
        "hover:text-primary/80 outline-none",
        "focus-visible:ring-ring focus-visible:ring-2 focus-visible:ring-offset-2",
        className,
      )}
      {...props}
    />
  );
}
