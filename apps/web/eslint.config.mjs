import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";
import prettier from "eslint-config-prettier/flat";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,

  globalIgnores([
    // Default ignores of eslint-config-next.
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",

    // Playwright's output. The HTML report bundles its own JavaScript, and a
    // lint failure in a file nobody wrote is one that teaches people to skip
    // the lint.
    "test-results/**",
    "playwright-report/**",
    "blob-report/**",
  ]),

  // Last on purpose. This switches off every ESLint rule that would otherwise
  // argue with Prettier about layout, which is what keeps the boundary in
  // CLAUDE.md enforceable rather than merely stated: ESLint owns code quality,
  // Prettier owns formatting.
  //
  // Note what is deliberately *not* here: a lint rule forbidding an absolute
  // API URL in browser code. `import "server-only"` in lib/api/config.ts is a
  // build error rather than a warning, which is a stronger guarantee than a
  // rule somebody can disable on one line.
  prettier,
]);

export default eslintConfig;
