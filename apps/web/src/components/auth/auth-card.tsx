import type { ReactNode } from "react";

/**
 * The card an auth screen is written on: a title, a sentence, and the form.
 *
 * Composed rather than a `ui/` primitive, because it is one arrangement used by
 * five screens rather than a piece anything else would reach for. If a second
 * kind of page ever wants a card, that is the moment a generic one is extracted
 * from the two of them - not before (ADR 0019).
 *
 * The heading is an `h1`. These pages have no other heading and a person
 * navigating by headings should land on what the page is for.
 */
export function AuthCard({
  title,
  description,
  children,
  footer,
}: {
  title: string;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <div className="space-y-4">
      <section className="bg-card border-border space-y-5 rounded-lg border p-6 shadow-sm">
        <header className="space-y-1.5">
          <h1 className="text-lg font-semibold tracking-tight">{title}</h1>
          {description ? (
            <p className="text-muted-foreground text-sm leading-relaxed">{description}</p>
          ) : null}
        </header>

        {children}
      </section>

      {footer ? <p className="text-muted-foreground text-center text-sm">{footer}</p> : null}
    </div>
  );
}
