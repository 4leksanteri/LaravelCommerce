import Link from "next/link";
import { unstable_rethrow } from "next/navigation";

import { ProductGrid } from "@/components/catalogue/product-grid";
import { buttonStyles } from "@/components/ui/button";
import { serverFetch } from "@/lib/api/server";
import type { CategoryTree, PublicProductPage, Resource } from "@/lib/api/types";

/**
 * The front door.
 *
 * Built from the design export's home screen, minus the parts with nothing
 * behind them: a hero photograph (there is none to show), a "this week, up to
 * 40% off" strip (there are no discounts), and a "How escrow works" button that
 * led to a page nobody wrote. The escrow explanation is here instead, in three
 * steps, because it is the one thing this marketplace does that the others do
 * not.
 *
 * `GET /search` with no query is "everything, newest first" - ADR 0020 made the
 * term optional for exactly this page - so "New in" is the first page of it
 * rather than a second endpoint shaped like the first.
 */
const NEW_IN_COUNT = 8;

export default async function HomePage() {
  const [categories, newest] = await Promise.all([readCategories(), readNewest()]);

  return (
    <div className="mx-auto w-full max-w-7xl space-y-14 px-4 py-10 sm:py-14">
      <section className="max-w-2xl space-y-5">
        <p className="text-primary text-xs font-bold tracking-widest uppercase">
          Shop from people, not warehouses
        </p>
        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">
          Buy from small shops. Pay only when it arrives.
        </h1>
        <p className="text-muted-foreground text-base leading-relaxed">
          We hold your money until you confirm the parcel arrived. The shop gets paid, and you get
          what you ordered.
        </p>
        <Link href="/search" className={buttonStyles({ size: "default" })}>
          Start browsing
        </Link>
      </section>

      <EscrowSteps />

      {categories.length > 0 ? (
        <section className="space-y-4" aria-labelledby="categories-heading">
          <h2 id="categories-heading" className="text-lg font-semibold tracking-tight">
            Browse by category
          </h2>

          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            {categories.map((category) => (
              <li key={category.slug}>
                <Link
                  href={`/categories/${category.slug}`}
                  className="bg-card border-border hover:border-primary/40 focus-visible:ring-ring block h-full space-y-1 rounded-lg border p-4 outline-none focus-visible:ring-2"
                >
                  <span className="block text-sm font-semibold">{category.name}</span>
                  {category.children.length > 0 ? (
                    <span className="text-muted-foreground block text-xs leading-relaxed">
                      {category.children
                        .slice(0, 3)
                        .map((child) => child.name)
                        .join(", ")}
                    </span>
                  ) : null}
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <section className="space-y-4" aria-labelledby="new-in-heading">
        <div className="flex items-baseline justify-between gap-4">
          <h2 id="new-in-heading" className="text-lg font-semibold tracking-tight">
            New in
          </h2>
          {newest.length > 0 ? (
            <Link href="/search" className="text-primary text-sm font-medium hover:underline">
              See everything
            </Link>
          ) : null}
        </div>

        {newest.length > 0 ? (
          <ProductGrid products={newest.slice(0, NEW_IN_COUNT)} />
        ) : (
          // Said plainly rather than filled with placeholders. An empty
          // marketplace with fake listings in it is a marketplace that lies on
          // its front page.
          <p className="bg-card border-border text-muted-foreground rounded-lg border p-6 text-sm">
            Nothing is listed yet. Shops are still setting up.
          </p>
        )}
      </section>
    </div>
  );
}

/**
 * Who has the money, at each step.
 *
 * The export's design notes reserve **amber for money being held** and **green
 * for money released**, so that escrow state reads at a glance everywhere. This
 * is the first place that rule is used, and `caution` and `positive` are the
 * tokens that carry it (ADR 0019).
 *
 * Every sentence here is a behaviour the API implements rather than a promise:
 * completion on confirmation, or fourteen days after dispatch, extendable twice
 * by a buyer whose parcel is late (ADR 0014). If one of those changes, this
 * changes with it.
 */
function EscrowSteps() {
  const steps = [
    {
      title: "You pay",
      body: "Your money is held by us, not sent to the shop.",
      tone: "bg-caution/10 text-caution",
      label: "Held",
    },
    {
      title: "The shop ships",
      body: "They can see it has been paid for, so they post it.",
      tone: "bg-caution/10 text-caution",
      label: "Held",
    },
    {
      title: "You confirm it arrived",
      body: "Then the shop is paid. If you forget, it releases 14 days after dispatch - and you can push that back twice if the post is slow.",
      tone: "bg-positive/10 text-positive",
      label: "Released",
    },
  ];

  return (
    <section aria-labelledby="escrow-heading" className="space-y-4">
      <h2 id="escrow-heading" className="text-lg font-semibold tracking-tight">
        How paying works here
      </h2>

      <ol className="grid gap-3 sm:grid-cols-3">
        {steps.map((step, index) => (
          <li key={step.title} className="bg-card border-border space-y-2 rounded-lg border p-5">
            <div className="flex items-center justify-between gap-3">
              <span className="text-muted-foreground text-xs font-semibold tabular-nums">
                {index + 1}
              </span>
              <span className={`${step.tone} rounded-sm px-1.5 py-0.5 text-xs font-semibold`}>
                {step.label}
              </span>
            </div>
            <h3 className="text-sm font-semibold">{step.title}</h3>
            <p className="text-muted-foreground text-sm leading-relaxed">{step.body}</p>
          </li>
        ))}
      </ol>
    </section>
  );
}

async function readCategories(): Promise<CategoryTree> {
  try {
    return (await serverFetch<Resource<CategoryTree>>("/categories")).data;
  } catch (error) {
    unstable_rethrow(error);
    console.error("The home page could not load categories.", error);

    return [];
  }
}

async function readNewest(): Promise<PublicProductPage> {
  try {
    return (await serverFetch<Resource<PublicProductPage>>("/search")).data;
  } catch (error) {
    unstable_rethrow(error);
    console.error("The home page could not load the newest listings.", error);

    return [];
  }
}
