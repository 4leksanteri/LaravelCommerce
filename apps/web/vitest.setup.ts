import "@testing-library/jest-dom/vitest";

import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";

// Testing Library only unmounts between tests on its own when `afterEach` is a
// global. The tests import it from `vitest` explicitly instead, so the cleanup
// is registered here - otherwise one test's DOM is still on the page for the
// next, and a query finds the wrong element.
afterEach(() => {
  cleanup();
});
