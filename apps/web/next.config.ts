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
};

export default nextConfig;
