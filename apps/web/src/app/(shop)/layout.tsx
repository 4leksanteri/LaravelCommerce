import { SiteFooter } from "@/components/shell/site-footer";
import { SiteHeader } from "@/components/shell/site-header";

/**
 * Every page a shopper browses, wrapped in the header and footer.
 *
 * A route group, as `(auth)` is, so the difference between the two is a
 * directory rather than a condition. The auth screens deliberately have no
 * chrome - somebody signing in is doing one thing - and a layout that checked
 * the path to decide whether to draw a header would be that decision made in
 * the wrong place.
 */
export default function ShopLayout({ children }: LayoutProps<"/">) {
  return (
    <>
      <SiteHeader />
      <main className="flex-1">{children}</main>
      <SiteFooter />
    </>
  );
}
