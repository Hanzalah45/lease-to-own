"use client";

import { money } from "@/components/applications/wizard/types";
import { formatDateOnly } from "@/lib/dates";
import type { BillingCycle, BillingCyclePreview } from "@/types/lease-agreement";

const CYCLE_LABEL: Record<BillingCycle, string> = {
  "1st": "The 1st of each month",
  "15th": "The 15th of each month",
};

interface BillingCycleCardProps {
  preview: Record<BillingCycle, BillingCyclePreview> | undefined;
  value: BillingCycle | null;
  onChange: (cycle: BillingCycle) => void;
  error?: string | null;
}

/**
 * The customer's billing-cycle choice (client, Joel, 2026-10-05): the 1st or
 * the 15th, no custom dates, picked right before signing so the contract
 * states it. The examples assume pickup today, since the real pickup date is
 * not known until the equipment is handed over.
 */
export function BillingCycleCard({ preview, value, onChange, error }: BillingCycleCardProps) {
  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <div className="mb-1 flex items-center gap-2">
        <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
        <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Choose your billing day</h2>
      </div>
      <p className="mb-4 text-sm text-neutral-500">
        Your payments are due on the 1st or the 15th of each month, whichever you pick. Your first payment is a full
        month, due the day you pick up your equipment. Amounts below are the bank prices; paying by card costs a bit
        more.
      </p>

      <div className="space-y-3">
        {(["1st", "15th"] as const).map((cycle) => {
          const example = preview?.[cycle];
          const selected = value === cycle;

          return (
            <label
              key={cycle}
              className={`block cursor-pointer rounded-md border p-3.5 text-sm ${
                selected ? "border-red-600 bg-red-50" : "border-neutral-300 hover:bg-neutral-50"
              }`}
            >
              <span className="flex items-center gap-2.5 font-semibold text-neutral-900">
                <input
                  type="radio"
                  name="billing-cycle"
                  checked={selected}
                  onChange={() => onChange(cycle)}
                  className="accent-red-600"
                />
                {CYCLE_LABEL[cycle]}
              </span>
              {example && (
                <span className="mt-2 block text-xs leading-relaxed text-neutral-600">
                  If you picked up today: {money(example.first_payment_amount)} on {formatDateOnly(example.first_payment_date)}
                  , then{" "}
                  {example.second_payment_prorated
                    ? `${money(example.second_payment_amount)} on ${formatDateOnly(example.second_payment_date)} (a partial month, ${example.days_until_second_payment} ${example.days_until_second_payment === 1 ? "day" : "days"})`
                    : `${money(example.second_payment_amount)} on ${formatDateOnly(example.second_payment_date)}`}
                  , then {money(example.recurring_amount)} on the {cycle} of every month after that.
                  <span className="mt-1 block text-neutral-400">
                    By card: {money(example.first_payment_card)}, {money(example.second_payment_card)}, then {money(example.recurring_card)} a month.
                  </span>
                </span>
              )}
            </label>
          );
        })}
      </div>

      {error && <p className="mt-2 text-xs text-red-600">{error}</p>}
    </div>
  );
}
