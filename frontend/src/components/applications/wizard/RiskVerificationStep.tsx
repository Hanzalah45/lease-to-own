import { Field, SelectInput, TextInput } from "@/components/applications/wizard/fields";
import { fieldError, type WizardState } from "@/components/applications/wizard/types";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { CheckIcon } from "@/components/icons";

const RESIDENCE_OPTIONS = [
  { value: "own_single", label: "Own — Single Family" },
  { value: "own_multi", label: "Own — Multi Family" },
  { value: "rent_house", label: "Rent — House" },
  { value: "rent_apartment", label: "Rent — Apartment" },
  { value: "other", label: "Other" },
];

const YEARS_OPTIONS = [
  { value: "lt1", label: "Less than 1 year" },
  { value: "1-3", label: "1 – 3 years" },
  { value: "3-5", label: "3 – 5 years" },
  { value: "5plus", label: "5+ years" },
];

const INCOME_OPTIONS = [
  { value: "employed", label: "Employed" },
  { value: "self_employed", label: "Self-employed" },
  { value: "retired", label: "Retired" },
  { value: "other", label: "Other" },
];

export function RiskVerificationStep({
  state,
  set,
  fieldErrors,
}: {
  state: WizardState;
  set: <K extends keyof WizardState>(key: K, value: WizardState[K]) => void;
  fieldErrors?: Record<string, string[]>;
}) {
  const isApartment = state.residenceType === "rent_apartment";
  const err = (key: string) => fieldError(fieldErrors, key);

  return (
    <div className="space-y-6">
      <div className="rounded-xl border border-neutral-200 bg-white p-6">
        <SectionHeading title="Residence & income" />
        <div className="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
          <Field
            label="Residence Type"
            required
            error={err("residence_type")}
            hint={isApartment ? "Apartments are automatically declined per underwriting policy." : undefined}
          >
            <SelectInput
              value={state.residenceType}
              onChange={(v) => set("residenceType", v)}
              options={RESIDENCE_OPTIONS}
              hasError={!!err("residence_type")}
            />
          </Field>
          <Field label="Years at Residence" required error={err("years_at_residence")}>
            <SelectInput
              value={state.yearsAtResidence}
              onChange={(v) => set("yearsAtResidence", v)}
              options={YEARS_OPTIONS}
              hasError={!!err("years_at_residence")}
            />
          </Field>

          <Field label="Income Source" required error={err("income_source")}>
            <SelectInput
              value={state.incomeSource}
              onChange={(v) => set("incomeSource", v)}
              options={INCOME_OPTIONS}
              hasError={!!err("income_source")}
            />
          </Field>
          <Field label="Gross Monthly Income" required error={err("gross_monthly_income")}>
            <TextInput
              value={state.grossMonthlyIncome}
              onChange={(v) => set("grossMonthlyIncome", v)}
              placeholder="5000"
              type="number"
              hasError={!!err("gross_monthly_income")}
            />
          </Field>
        </div>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-6">
        <SectionHeading title="Verification & consent" />
        <div className="mt-5 space-y-3">
          {[
            "Identity check against government ID + address",
            "Bank verification via Plaid (deposit history, pay frequency)",
            "Background check for fraud / theft / prior LTO defaults",
          ].map((label) => (
            <div key={label} className="flex items-center gap-2.5 text-sm text-neutral-700">
              <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-600">
                <CheckIcon className="h-3 w-3" />
              </span>
              {label}
            </div>
          ))}
          <div className="pt-1">
            <label className={`flex cursor-pointer items-center gap-2.5 text-sm ${err("move_notification_agreed") ? "text-red-600 font-semibold" : "text-neutral-700"}`}>
              <input
                type="checkbox"
                checked={state.moveNotificationAgreed}
                onChange={(e) => set("moveNotificationAgreed", e.target.checked)}
                className="h-4 w-4 accent-red-600"
              />
              Customer agrees to notify Outdoor Fix if they move, per lease terms. <span className="font-bold text-red-600">*</span>
            </label>
            {err("move_notification_agreed") && (
              <p className="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-red-600">
                <span className="inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-red-600" />
                {err("move_notification_agreed")}
              </p>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
