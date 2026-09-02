#!/usr/bin/env node
/**
 * Static validation-coverage check.
 *
 * This exists because of a real bug: "Sales Person Name" in the application
 * wizard had zero validation — no length limit, no format check — because
 * nobody ever added a `put(errors, "sales_person", ...)` line for it. The
 * field rendered fine, had an `error={err(...)}` prop wired, looked
 * complete... and just silently never validated anything.
 *
 * Part A (precise): parses the wizard's WizardState field list and its four
 * validateXStep() functions, and reports any state field whose mapped API
 * key is never referenced inside any of them. Zero false positives — this
 * is exactly the shape of bug that slipped through.
 *
 * Part B (heuristic): for a curated set of other form files, counts
 * `<input`/`<textarea`/`<select` tags against nearby `error=`/`hasError=`
 * wiring. This does NOT prove the validator is rigorous — only that the
 * field participates in the error-display system at all. Treat flags here
 * as "go look", not "definitely broken".
 *
 * Run: npm run test:validation-coverage
 * Exits 1 if Part A finds anything (that's the load-bearing check).
 */

import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const __dirname = dirname(fileURLToPath(import.meta.url));
const SRC = join(__dirname, "..", "src");

// Fields that are intentionally exempt from format/length validation —
// controlled inputs (radio/select with a fixed value set), or fields whose
// only rule is "must be selected", which the required-ness check next to
// them already covers via a plain `errors.key = [...]` assignment picked up
// by the same scan. Add a reason so this list can't silently grow.
const WIZARD_EXEMPT = new Set([
  "condition", // radio: "new" | "used" — can't hold an invalid value
  "ldw", // radio: "yes" | "no"
  "autopay", // radio: "yes" | "no"
  "id_document", // File | null — checked via `if (!state.idDocument)`, not `put()`
]);

function fail(lines) {
  console.error("\n❌ Validation coverage check failed:\n");
  for (const l of lines) console.error("  - " + l);
  console.error("\nEvery field listed above renders with error styling wired up but has no");
  console.error("actual validator call behind it, so it can never show an error, no matter");
  console.error("what the user types. Add a `put(errors, \"<field>\", validate...(...))` line");
  console.error("(or the equivalent required-ness check) in the matching validate*Step function.\n");
  process.exitCode = 1;
}

/** Strips // and /* *\/ comments so a commented-out `put(errors, ...)` can't count as coverage. */
function stripComments(text) {
  return text.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "");
}

function checkWizard() {
  const path = join(SRC, "components/applications/wizard/types.ts");
  const text = stripComments(readFileSync(path, "utf8"));

  const stateBlock = text.match(/export interface WizardState \{([\s\S]*?)\n\}/);
  if (!stateBlock) throw new Error("Could not find `export interface WizardState` in types.ts — script needs updating to match a refactor.");
  const stateFields = [...stateBlock[1].matchAll(/^\s*(\w+)\s*[?:]/gm)].map((m) => m[1]);

  const mapBlock = text.match(/export const STATE_TO_FIELD:[^{]*\{([\s\S]*?)\n\};/);
  if (!mapBlock) throw new Error("Could not find `STATE_TO_FIELD` in types.ts — script needs updating to match a refactor.");
  const stateToField = Object.fromEntries(
    [...mapBlock[1].matchAll(/(\w+):\s*"([^"]+)"/g)].map((m) => [m[1], m[2]]),
  );

  const stepFns = ["validateEquipmentStep", "validateLeaseStep", "validateCustomerStep", "validateRiskStep"];
  const referenced = new Set();
  for (const fn of stepFns) {
    const fnMatch = text.match(new RegExp(`export function ${fn}\\([\\s\\S]*?\\n\\}`));
    if (!fnMatch) throw new Error(`Could not find function ${fn} in types.ts — script needs updating to match a refactor.`);
    const body = fnMatch[0];
    for (const m of body.matchAll(/put\(\s*errors,\s*"([^"]+)"/g)) referenced.add(m[1]);
    for (const m of body.matchAll(/errors\.(\w+)\s*=/g)) referenced.add(m[1]);
    for (const m of body.matchAll(/errors\["([^"]+)"\]\s*=/g)) referenced.add(m[1]);
  }

  const gaps = [];
  for (const stateField of stateFields) {
    const apiField = stateToField[stateField];
    if (!apiField) continue; // not submitted to the API (e.g. a display-only derived field) — nothing to validate
    if (WIZARD_EXEMPT.has(apiField)) continue;
    if (!referenced.has(apiField)) {
      gaps.push(`wizard field "${stateField}" (→ "${apiField}") — no put(errors, "${apiField}", ...) or errors.${apiField} assignment in any validate*Step function`);
    }
  }
  return gaps;
}

/** Heuristic pass — counts input-like tags vs error-wiring props per file. Report only, doesn't fail the build. */
function checkHeuristic() {
  const files = [
    "components/customers/EditCustomerModal.tsx",
    "app/admin/admin-users/page.tsx",
    "components/equipment/EquipmentFormModal.tsx",
    "components/equipment/AssignUnitModal.tsx",
    "components/equipment/ReleaseUnitModal.tsx",
    "components/applications/detail/EditDetailModal.tsx",
    "components/applications/detail/DealerNotes.tsx",
    "components/auth/AuthCard.tsx",
  ];

  const notes = [];
  for (const rel of files) {
    let text;
    try {
      text = stripComments(readFileSync(join(SRC, rel), "utf8"));
    } catch {
      notes.push(`(skipped, not found: ${rel} — update the file list in this script if it moved)`);
      continue;
    }
    const inputs = (text.match(/<(input|textarea|select)\b/g) ?? []).length;
    // Wiring shows up two ways in this codebase: a literal error=/hasError=/
    // aria-invalid= prop, or a `xxxClass(!!somethingError)` helper call
    // (EditCustomerModal, admin-users, ReleaseUnitModal all do this instead
    // of a named prop). Count both so a helper-based file doesn't false-flag.
    const wired =
      (text.match(/\b(error|hasError|aria-invalid)=/g) ?? []).length +
      (text.match(/Class\([^)]*Error/g) ?? []).length;
    if (inputs > 0 && wired < inputs) {
      notes.push(`${rel}: ${inputs} input-like element(s), only ${wired} with error/hasError/aria-invalid wiring — worth a manual look`);
    }
  }
  return notes;
}

const wizardGaps = checkWizard();
const heuristicNotes = checkHeuristic();

if (heuristicNotes.length) {
  console.log("ℹ️  Heuristic pass (not build-failing, just a prompt to look):\n");
  for (const n of heuristicNotes) console.log("  - " + n);
  console.log();
}

if (wizardGaps.length) {
  fail(wizardGaps);
} else {
  console.log("✅ Wizard validation coverage: every WizardState field that reaches the API has a validator reference.");
}
