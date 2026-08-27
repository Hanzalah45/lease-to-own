export const STEPS = [
  { key: "equipment", label: "1 · Equipment" },
  { key: "lease", label: "2 · Lease Details" },
  { key: "customer", label: "3 · Customer Info" },
  { key: "risk", label: "4 · Risk & Verification" },
] as const;

export type StepKey = (typeof STEPS)[number]["key"];

export function WizardSteps({ active, onSelect }: { active: StepKey; onSelect: (key: StepKey) => void }) {
  return (
    <div className="grid grid-cols-2 gap-0 overflow-hidden rounded-md bg-neutral-100 sm:grid-cols-4">
      {STEPS.map((step) => (
        <button
          key={step.key}
          onClick={() => onSelect(step.key)}
          className={`font-heading px-4 py-3 text-xs font-bold uppercase tracking-wide transition sm:text-sm ${
            active === step.key ? "bg-red-600 text-white" : "text-neutral-500 hover:bg-neutral-200"
          }`}
        >
          {step.label}
        </button>
      ))}
    </div>
  );
}
