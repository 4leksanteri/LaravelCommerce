import { fileURLToPath } from "node:url";

import react from "@vitejs/plugin-react";
import tsconfigPaths from "vite-tsconfig-paths";
import { defineConfig } from "vitest/config";

/**
 * Unit and component tests: the frontend's own logic, and client components.
 *
 * Not pages. Vitest cannot render `async` Server Components - the bundled Next
 * docs say so in as many words - and nearly every page here is one. Those are
 * covered end to end by Playwright instead (`e2e/`, `make e2e`).
 */
export default defineConfig({
  plugins: [tsconfigPaths(), react()],

  resolve: {
    alias: {
      /*
       * `server-only` throws on import unless the `react-server` export
       * condition is active, which is how it turns a server module imported
       * from the browser into a build error. A test importing the proxy or
       * `serverFetch` is exercising server code on purpose, so it gets the
       * package's own empty module - the file a server build would resolve.
       *
       * Deliberately not `resolve.conditions: ["react-server"]`. That would
       * also swap React itself for its server build, and every client
       * component test would stop working.
       */
      "server-only": fileURLToPath(new URL("./node_modules/server-only/empty.js", import.meta.url)),
    },
  },

  test: {
    environment: "jsdom",
    include: ["src/**/*.test.{ts,tsx}"],
    setupFiles: ["./vitest.setup.ts"],

    // Nothing a test changes about the world may reach the next test.
    clearMocks: true,
    restoreMocks: true,
    unstubEnvs: true,
    unstubGlobals: true,
  },
});
