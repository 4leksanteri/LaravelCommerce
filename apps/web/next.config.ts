import { fileURLToPath } from "node:url";

import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Emits .next/standalone: the server plus only the dependencies it actually
  // reached for. The production image copies that and nothing else - no pnpm,
  // no source tree, no dev dependencies. See docker/web/Dockerfile.
  output: "standalone",

  // The build runs from apps/web, but the workspace's node_modules live at the
  // repository root. Without this, tracing follows symlinks out of the app
  // directory and leaves files the standalone server needs behind.
  //
  // fileURLToPath rather than URL.pathname: pathname stays percent-encoded, so
  // a checkout in a directory whose name contains a space resolves to a path
  // that does not exist and the build fails on it.
  outputFileTracingRoot: fileURLToPath(new URL("../../", import.meta.url)),

  // The API is the only thing that ever says what this server is running.
  poweredByHeader: false,

  // A failing type check must fail the build. This is already the default; it
  // is written down because turning it on to get a deploy out is exactly the
  // decision that should require editing a file with a comment in it.
  //
  // There is no `eslint` key here. Next 16 no longer runs ESLint during a
  // build, so linting is a separate step - `make lint`, and CI - rather than
  // something the build quietly does on your behalf.
  typescript: { ignoreBuildErrors: false },

  images: {
    // The optimiser may fetch product photographs and nothing else on this
    // origin. Without a pattern it will resize any local path it is handed,
    // which makes `/_next/image` a way to pull arbitrary routes through a cache.
    //
    // `search: ""` is the part that matters. A photograph on a public listing
    // has a plain URL; one that is not on sale is served against a signature
    // that expires within the hour (ADR 0016), and its URL carries that in the
    // query string. The optimiser caches by URL and keeps the result for as long
    // as it likes, so letting a signed URL through would keep serving a private
    // image after its signature had expired. Refusing any query string keeps
    // those out entirely: they are rendered unoptimised, from the signed URL
    // itself, and stop working when it does.
    localPatterns: [{ pathname: "/api/v1/images/**", search: "" }],
  },
};

export default nextConfig;
