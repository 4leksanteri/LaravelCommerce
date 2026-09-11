import Link from "next/link";

/**
 * The shell every auth screen sits in.
 *
 * A route group, so `(auth)` shapes these five pages without appearing in any
 * URL: the sign-in page is `/login`, not `/auth/login`.
 *
 * Deliberately narrow and centred, with no header and no navigation. Somebody
 * on these pages is doing one thing, and the marketplace's chrome would offer
 * them somewhere else to go in the middle of it. The wordmark is the way back.
 */
export default function AuthLayout({ children }: LayoutProps<"/">) {
  return (
    <main className="flex flex-1 flex-col items-center justify-center px-4 py-10 sm:py-16">
      <div className="w-full max-w-sm space-y-6">
        <div className="flex justify-center">
          <Link
            href="/"
            className="focus-visible:ring-ring rounded-sm text-base font-semibold tracking-tight outline-none focus-visible:ring-2 focus-visible:ring-offset-4 focus-visible:ring-offset-[var(--background)]"
          >
            Laravel Commerce
          </Link>
        </div>

        {children}
      </div>
    </main>
  );
}
