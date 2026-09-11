import Link from "next/link";

import { ShopStatusBadge } from "@/components/sellers/shop-status-badge";
import { SideNav } from "@/components/ui/side-nav";
import { currentUser } from "@/lib/auth/session";
import { readShop } from "@/lib/sellers/shop";

/**
 * Your account and your shop, in one layout.
 *
 * The design export gives the seller an application of its own: a dark sidebar
 * against the edge of the screen and no site header. This is the deliberate
 * alternative (ADR 0033). Selling is not a role here (root CLAUDE.md section
 * 6a) - a shop owner is a customer who also has a shop - so their shop sits
 * beside their orders, under the same header, in the same page.
 *
 * **A route group, so it is one file.** `/account/**` and `/seller/**` are
 * separate addresses drawn by this one layout: a `w-80` column of links on the
 * left, and the page in the rest of the width. The shop's section appears once
 * there is a shop; until then it is a way to open one.
 *
 * **It guards nothing.** A layout is not told the path, so it cannot send
 * somebody to sign in and back to the right place. Every page calls
 * `requireUser` with its own address, and for a signed-out visitor this draws
 * only the page, which redirects.
 */
export default async function DashboardLayout({ children }: LayoutProps<"/">) {
  const user = await currentUser();

  if (!user) {
    return children;
  }

  const shop = user.has_shop ? await readShop() : null;

  return (
    <div className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8 sm:py-10 lg:flex-row lg:gap-10">
      {/*
       * Named, so it stays one distinct landmark when a page beside it has an
       * aside of its own - two unnamed ones is what axe refused on an order's
       * page before that page's aside became a div.
       */}
      <aside
        aria-label="Your account and shop"
        className="lg:border-border space-y-6 lg:w-80 lg:shrink-0 lg:border-r lg:pr-6"
      >
        <div className="min-w-0 px-3">
          <p className="truncate font-semibold">{user.name}</p>
          <p className="text-muted-foreground truncate text-sm">{user.email}</p>
        </div>

        <div className="space-y-2">
          <p className="text-muted-foreground px-3 text-xs font-semibold tracking-wide uppercase">
            Your account
          </p>
          <SideNav
            label="Your account"
            items={[
              { href: "/account", label: "Overview", exact: true },
              { href: "/account/orders", label: "Orders" },
            ]}
          />
        </div>

        <div className="space-y-2">
          <p className="text-muted-foreground px-3 text-xs font-semibold tracking-wide uppercase">
            Your shop
          </p>
          {shop ? (
            <>
              <div className="min-w-0 space-y-1 px-3">
                <p className="truncate text-sm font-semibold">{shop.shop_name}</p>
                <ShopStatusBadge status={shop.status} />
              </div>
              <SideNav
                label="Your shop"
                items={[
                  { href: "/seller", label: "Overview", exact: true },
                  { href: "/seller/settings", label: "Shop settings" },
                ]}
              />
            </>
          ) : (
            <Link
              href="/sell"
              className="text-primary block px-3 py-2 text-sm font-medium hover:underline"
            >
              Open a shop
            </Link>
          )}
        </div>
      </aside>

      <div className="min-w-0 flex-1">{children}</div>
    </div>
  );
}
