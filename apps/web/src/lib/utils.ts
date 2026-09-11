import { type ClassValue, clsx } from "clsx";
import { twMerge } from "tailwind-merge";

/**
 * Merge class names, letting the caller's win.
 *
 * This is shadcn's contract rather than an invention, which is the reason to
 * keep the name and the signature: a primitive copied in from shadcn calls
 * `cn()` and needs no hand-editing to work here.
 *
 * `clsx` flattens conditionals; `tailwind-merge` resolves conflicts by
 * specificity rather than order, so `cn("px-4", "px-6")` is `px-6` and not
 * both. Without the second, every component that accepts a `className` would
 * be a component whose padding cannot be overridden.
 */
export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}
