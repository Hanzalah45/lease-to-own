"use client";

import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { AlertCircleIcon, CheckIcon, ClockIcon } from "@/components/icons";
import type { ApplicationProgress, StepState } from "@/lib/application-progress";

const STATE_STYLE: Record<StepState, { ring: string; text: string }> = {
  done: { ring: "bg-green-600 text-white", text: "text-neutral-500" },
  waiting: { ring: "border border-neutral-300 bg-white text-neutral-400", text: "text-neutral-500" },
  attention: { ring: "bg-red-100 text-red-600", text: "font-medium text-red-600" },
  manual: { ring: "bg-amber-100 text-amber-700", text: "text-amber-700" },
  off: { ring: "bg-neutral-100 text-neutral-400", text: "text-neutral-400" },
};

function StateIcon({ state }: { state: StepState }) {
  if (state === "done" || state === "manual") return <CheckIcon className="h-3 w-3" />;
  if (state === "attention") return <AlertCircleIcon className="h-3.5 w-3.5" />;
  if (state === "waiting") return <ClockIcon className="h-3 w-3" />;
  return <span className="h-0.5 w-2.5 rounded bg-current" />;
}

/**
 * Where the customer is in the process, read from what the system actually
 * knows (see computeApplicationProgress). Replaces the fixed text that never
 * changed after the customer signed (client, Joel, 2026-10-13).
 */
export function ProgressCard({ progress, showNextStep = true }: { progress: ApplicationProgress; showNextStep?: boolean }) {
  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <SectionHeading title="Customer progress" />
      {showNextStep && (
        <p className="mt-3 text-sm text-neutral-600">
          <span className="font-semibold text-neutral-900">Next step:</span> {progress.nextStep}
        </p>
      )}
      <ul className="mt-4 space-y-3">
        {progress.steps.map((step) => {
          const style = STATE_STYLE[step.state];
          return (
            <li key={step.key} className="flex items-start gap-3">
              <span className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full ${style.ring}`}>
                <StateIcon state={step.state} />
              </span>
              <div className="min-w-0">
                <p className="text-sm font-semibold text-neutral-900">{step.label}</p>
                <p className={`text-xs ${style.text}`}>{step.detail}</p>
              </div>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
