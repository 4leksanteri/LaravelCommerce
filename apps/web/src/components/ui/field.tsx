import { useId, type ComponentProps, type ReactNode } from "react";

import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

/**
 * A labelled input, its hint, and the messages the API sent about it.
 *
 * **This exists because of how Laravel answers a 422.** The body is
 * `{ errors: { field: [messages] } }`, and `apps/web/CLAUDE.md` section 9 says
 * those belong beside the field that caused them rather than in a banner. Doing
 * that correctly means wiring `aria-describedby` and `aria-invalid` on every
 * input, which is the sort of thing that gets done on three fields out of five
 * unless one component owns it.
 *
 * `errors` is the array as it arrives. A field can carry more than one message
 * and Laravel sends all of them, so all of them are rendered - picking the
 * first would hide "this is too short" behind "this has been leaked".
 *
 * The ids are generated with `useId` so the same field can appear twice on a
 * page without colliding, and so server and client render the same markup.
 */
type FieldProps = Omit<ComponentProps<"input">, "id"> & {
  label: string;
  /** Standing guidance: a password rule, what an address is for. Always shown. */
  hint?: ReactNode;
  /** Laravel's messages for this field, in the order it sent them. */
  errors?: string[];
};

export function Field({ label, hint, errors, className, ...props }: FieldProps) {
  const id = useId();
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;
  const invalid = (errors?.length ?? 0) > 0;

  return (
    <div className={cn("space-y-1.5", className)}>
      <label htmlFor={id} className="text-foreground block text-sm font-medium">
        {label}
      </label>

      <Input
        id={id}
        aria-invalid={invalid || undefined}
        aria-describedby={cn(hint && hintId, invalid && errorId) || undefined}
        {...props}
      />

      {hint ? (
        <p id={hintId} className="text-muted-foreground text-xs">
          {hint}
        </p>
      ) : null}

      {invalid ? (
        <ul id={errorId} className="text-destructive space-y-1 text-xs">
          {errors?.map((message) => (
            <li key={message}>{message}</li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
