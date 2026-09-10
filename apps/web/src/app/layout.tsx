import type { Metadata } from "next";
import { Manrope } from "next/font/google";

import "./globals.css";

/**
 * One family, as the design export uses (ADR 0019).
 *
 * A geometric sans that reads as precise, which is what a marketplace for
 * secondhand equipment wants - the serif pairing an earlier direction called for
 * said "made by hand", and this product is not making that claim.
 *
 * There is no second webfont. Order references and serials are rendered in a
 * system monospace stack: downloading a typeface for ten characters is not a
 * trade worth making.
 */
const manrope = Manrope({
  variable: "--font-manrope",
  subsets: ["latin"],
  display: "swap",
});

export const metadata: Metadata = {
  title: "Laravel Commerce",
  description: "A marketplace with escrow. Buy from small shops, pay only when it arrives.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className={`${manrope.variable} h-full antialiased`}>
      <body className="bg-background text-foreground flex min-h-full flex-col font-sans">
        {children}
      </body>
    </html>
  );
}
