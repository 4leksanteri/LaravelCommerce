import type { Metadata } from "next";
import { Fraunces, Geist_Mono, Inter } from "next/font/google";

import "./globals.css";

/**
 * Three faces, each with a job (ADR 0018).
 *
 * The scaffold shipped Geist for everything, which is a good typeface for a
 * developer tool and wrong for a marketplace of people who make things by hand -
 * it reads as software.
 */

/**
 * Display only: headings, product names, prices. A variable serif built for
 * exactly this warmth, and keeping it off body text is what stops it becoming
 * twee.
 */
const fraunces = Fraunces({
  variable: "--font-fraunces",
  subsets: ["latin"],
  display: "swap",
});

/** Deliberately boring. A UI typeface should not have opinions at 14px. */
const inter = Inter({
  variable: "--font-inter",
  subsets: ["latin"],
  display: "swap",
});

/**
 * Order references. `8Y9JN63MTC` is a string people read down a telephone and
 * type back in, and a proportional font makes that harder.
 */
const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
  display: "swap",
});

export const metadata: Metadata = {
  title: "Laravel Commerce",
  description: "A marketplace for independent sellers.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      className={`${fraunces.variable} ${inter.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body className="bg-background text-foreground flex min-h-full flex-col font-sans">
        {children}
      </body>
    </html>
  );
}
