#!/usr/bin/env node
/**
 * Enforces the character-set rule in CLAUDE.md section 15.
 *
 * Source and prose are ASCII. The exception is the small set of box-drawing
 * and arrow characters the architecture diagrams are made of, which are
 * deliberate, legible, and confusable with nothing.
 *
 * Everything else non-ASCII is refused, and the two categories that matter are
 * worth naming:
 *
 *   Invisible    A non-breaking space or a zero-width joiner is unreadable in
 *                review by definition. One that drifts into a command or a
 *                test expectation costs real time to find.
 *   Confusable   A smart quote and a straight quote look identical at a glance
 *                and behave differently in every shell and most parsers. An em
 *                dash and a hyphen are the same problem in prose.
 *
 * Note that the banned characters below are written as escapes rather than as
 * themselves. A checker that has to contain what it forbids would fail its own
 * check, which is how this file was written the first time.
 *
 * Run with `make charset`, which `make lint` includes.
 */
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";

/** Deliberate, and expected to stay. The diagrams are made of these. */
const DIAGRAM_CHARACTERS = new Set([..."│─┌┐└┘├┤┬┴┼", ..."←↑→↓▶◀▲▼"]);

/** Named so a failure says what was found rather than only a code point. */
const NAMED = new Map([
  ["\u00a0", "non-breaking space"],
  ["\u2007", "figure space"],
  ["\u202f", "narrow non-breaking space"],
  ["\u200b", "zero-width space"],
  ["\u200c", "zero-width non-joiner"],
  ["\u200d", "zero-width joiner"],
  ["\ufeff", "byte-order mark"],
  ["\u2013", "en dash"],
  ["\u2014", "em dash"],
  ["\u2018", "left single quote"],
  ["\u2019", "right single quote"],
  ["\u201c", "left double quote"],
  ["\u201d", "right double quote"],
  ["\u2026", "ellipsis"],
]);

const CHECKED_EXTENSIONS = new Set([
  "php",
  "ts",
  "tsx",
  "js",
  "mjs",
  "cjs",
  "css",
  "md",
  "json",
  "yml",
  "yaml",
  "neon",
  "sh",
  "example",
]);

/**
 * Vendored, generated, or verbatim upstream text. Two are worth explaining:
 *
 *   LICENSE               the AGPL as published. Re-punctuating it would make
 *                         it a modified licence.
 *   docs/design/exports/  bundled applications from an external design tool.
 *                         Designer-written copy uses typographic punctuation and
 *                         the bundle carries base64 payloads; neither is ours to
 *                         re-punctuate, and nothing here is edited by hand.
 *
 *   apps/web/AGENTS.md    written by `next dev`, which re-adds its own block
 *                         on every run. It contains em dashes. Fixing them
 *                         produces a file Next immediately rewrites, so the
 *                         choice is to exempt it or to fail this check
 *                         forever.
 */
const SKIPPED = [
  /^LICENSE$/,
  /^apps\/web\/AGENTS\.md$/,
  /^docs\/design\/exports\//,
  /(^|\/)vendor\//,
  /(^|\/)node_modules\//,
  /^pnpm-lock\.yaml$/,
];

function isChecked(path) {
  if (SKIPPED.some((pattern) => pattern.test(path))) return false;
  if (path === "Makefile" || path.includes("Dockerfile")) return true;

  return CHECKED_EXTENSIONS.has(path.split(".").pop() ?? "");
}

/**
 * Tracked files plus untracked ones git would not ignore. `--others` matters:
 * before the first commit nothing is tracked, and a checker that silently
 * passes on an empty list is worse than no checker.
 */
function candidateFiles() {
  const output = execFileSync("git", ["ls-files", "--cached", "--others", "--exclude-standard"], {
    encoding: "utf8",
    maxBuffer: 32 * 1024 * 1024,
  });

  return output.split("\n").filter(Boolean).filter(isChecked);
}

function findings(path) {
  let contents;
  try {
    contents = readFileSync(path, "utf8");
  } catch {
    return [];
  }

  const found = [];

  contents.split("\n").forEach((line, index) => {
    for (const character of line) {
      const code = character.codePointAt(0) ?? 0;

      if (code < 128) continue;
      if (DIAGRAM_CHARACTERS.has(character)) continue;

      const hex = code.toString(16).toUpperCase().padStart(4, "0");
      const name = NAMED.get(character) ?? "non-ASCII character";

      found.push(`${path}:${index + 1}  ${name} (U+${hex})`);
      break; // One report per line is enough to find it.
    }
  });

  return found;
}

const files = candidateFiles();
const problems = files.flatMap(findings);

if (problems.length > 0) {
  console.error("Characters that must not appear in this repository:\n");
  for (const problem of problems) console.error(`  ${problem}`);
  console.error(
    "\nSource and prose are ASCII. Write '-' rather than a dash, a straight quote\n" +
      "rather than a smart one, and the escape (\\u00a0) where a specific space is\n" +
      "genuinely meant. See CLAUDE.md section 15.",
  );
  process.exit(1);
}

console.log(`Charset OK (${files.length} files).`);
