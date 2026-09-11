import { existsSync } from "node:fs";
import path from "node:path";

import { defineConfig, devices } from "@playwright/test";

/**
 * End to end, in a real browser, against the stack `make dev` is running.
 *
 * Not against a server Playwright starts itself. The thing worth proving is the
 * whole arrangement - browser, Next proxy, Laravel, PostgreSQL, a real inbox -
 * and a bare `next start` has no API behind it.
 *
 * The ports come from the repository's `.env`, the one file that says where
 * things are published (root `CLAUDE.md` section 12). Nothing here hard-codes
 * a port somebody else's machine does not use.
 */
const rootEnv = path.resolve(__dirname, "../../.env");

if (existsSync(rootEnv)) {
  process.loadEnvFile(rootEnv);
}

const webPort = process.env.WEB_PORT ?? "3000";

export default defineConfig({
  testDir: "./e2e",

  // One worker, in order. Every test writes to the same development database
  // and reads the same inbox, and two of them racing would fail each other in
  // ways that look like application bugs.
  fullyParallel: false,
  workers: 1,

  forbidOnly: Boolean(process.env.CI),
  retries: 0,

  reporter: [["list"], ["html", { open: "never" }]],

  use: {
    baseURL: `http://localhost:${webPort}`,
    trace: "retain-on-failure",
  },

  // Chromium only. The point is the arrangement rather than browser quirks,
  // and each extra engine is a few hundred megabytes on a disk that has
  // already filled up once.
  //
  // `setup` signs in as the demo shopper once and saves the session, and every
  // test that needs somebody signed in reuses it. Registration is limited to
  // ten an hour per IP and signing in to five a minute per address - the
  // production limits - so the suite spends one sign-in rather than an account
  // per test (e2e/support/session.ts).
  projects: [
    { name: "setup", testMatch: /auth\.setup\.ts/ },
    { name: "chromium", use: { ...devices["Desktop Chrome"] }, dependencies: ["setup"] },
  ],
});
