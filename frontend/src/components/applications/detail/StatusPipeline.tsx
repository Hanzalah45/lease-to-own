import { CheckCircleIcon, ClockIcon, CreditCardIcon, DocumentIcon, ShieldIcon, ThumbsUpIcon } from "@/components/icons";
import type { AppStatus } from "@/components/applications/detail/types";

const STEPS = [
  { key: "waiting_review", label: "Waiting review", icon: DocumentIcon },
  { key: "waiting_approval", label: "Waiting on approval", icon: ClockIcon },
  { key: "in_verification", label: "In verification", icon: ShieldIcon },
  { key: "waiting_deposit", label: "Waiting on deposit", icon: CreditCardIcon },
  { key: "waiting_delivery", label: "Waiting on delivery", icon: ThumbsUpIcon },
  { key: "finished", label: "Finished", icon: CheckCircleIcon },
] as const;

export function StatusPipeline({ status }: { status: AppStatus }) {
  const effective = status === "needs_info" ? "waiting_review" : status;
  const activeIndex = STEPS.findIndex((s) => s.key === effective);

  return (
    <div className="flex items-start rounded-xl border border-neutral-200 bg-white px-4 py-6 sm:px-6">
      {STEPS.map((step, i) => {
        const reached = i <= activeIndex;
        const StepIcon = step.icon;
        return (
          <div key={step.key} className="flex flex-1 items-center last:flex-none">
            <div className="flex flex-col items-center gap-2">
              <span
                className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 ${
                  reached ? "border-red-600 text-red-600" : "border-neutral-200 text-neutral-300"
                }`}
              >
                <StepIcon className="h-4 w-4" />
              </span>
              <span
                className={`font-heading whitespace-nowrap text-[10px] font-bold uppercase tracking-wide ${
                  reached ? "text-red-600" : "text-neutral-400"
                }`}
              >
                {step.label}
              </span>
            </div>
            {i < STEPS.length - 1 && (
              <div className={`mx-1.5 h-0.5 flex-1 sm:mx-2 ${i < activeIndex ? "bg-red-600" : "bg-neutral-200"}`} />
            )}
          </div>
        );
      })}
    </div>
  );
}
