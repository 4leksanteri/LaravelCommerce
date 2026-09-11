import { ProductCard } from "@/components/catalogue/product-card";
import type { PublicProduct } from "@/lib/api/types";

/**
 * Listings, laid out as cards.
 *
 * Extracted when the search page became the second thing to need exactly the
 * grid the home page already had - not before, when it would have been a
 * component with one caller (ADR 0019).
 *
 * Keyed by shop and slug together, because a slug is unique only within its
 * shop: two sellers can both list a Boss DS-1 under the same slug, and React
 * reusing one card's DOM for the other is a bug that shows up as the wrong
 * photograph.
 *
 * **It belongs under an h2.** Each card titles itself with an h3, so a page
 * that puts the grid straight under its h1 skips a heading level. The search
 * page did, and the axe check in `e2e/pages.spec.ts` found it on its first
 * run; that page now gives its results a visually hidden heading.
 */
export function ProductGrid({ products }: { products: PublicProduct[] }) {
  return (
    <ul className="grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-4">
      {products.map((product) => (
        <li key={`${product.shop_slug}/${product.slug}`}>
          <ProductCard product={product} />
        </li>
      ))}
    </ul>
  );
}
