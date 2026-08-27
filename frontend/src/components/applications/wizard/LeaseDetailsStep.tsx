import { Field, RadioGroup, TextInput } from "@/components/applications/wizard/fields";
import { SidebarCard } from "@/components/applications/wizard/SidebarCard";
import { EpoChart } from "@/components/applications/wizard/EpoChart";
import { computeLeasePricing, money, type WizardState } from "@/components/applications/wizard/types";
import { SectionHeading } from "@/components/dashboard/SectionHeading";

export function LeaseDetailsStep({
  state,
  set,
}: {
  state: WizardState;
  set: <K extends keyof WizardState>(key: K, value: WizardState[K]) => void;
}) {
  const pricing = computeLeasePricing(state);

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_20rem]">
        <div className="rounded-xl border border-neutral-200 bg-white p-6">
          <SectionHeading title="Lease details" />
          <div className="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
            <Field label="Lease Months to Ownership" required>
              <TextInput value={state.termMonths} onChange={(v) => set("termMonths", v)} placeholder="36" type="number" />
            </Field>
            <Field label="Sales Tax %">
              <TextInput value={state.taxRate} onChange={(v) => set("taxRate", v)} placeholder="8.25" type="number" />
            </Field>

            <Field label="Monthly Rental Payment" required>
              <TextInput value={state.monthlyRental} onChange={(v) => set("monthlyRental", v)} placeholder="0.00" type="number" />
            </Field>
            <Field label="Sales Tax">
              <TextInput value={money(pricing.salesTax)} onChange={() => {}} />
            </Field>

            <Field label="Total Monthly Payment">
              <TextInput value={money(pricing.totalMonthlyPayment)} onChange={() => {}} />
            </Field>
            <Field label="Security Deposit">
              <TextInput value={state.securityDeposit} onChange={(v) => set("securityDeposit", v)} placeholder="0.00" type="number" />
            </Field>

            <Field label="TOTAL DUE">
              <TextInput value={money(pricing.totalDueToday)} onChange={() => {}} />
            </Field>
            <Field label="Payment Due Day" required>
              <TextInput value={state.paymentDueDay} onChange={(v) => set("paymentDueDay", v)} placeholder="15th" />
            </Field>

            <Field label="Enroll in AutoPay?">
              <RadioGroup
                value={state.autopay}
                onChange={(v) => set("autopay", v as WizardState["autopay"])}
                options={[
                  { value: "yes", label: "Yes" },
                  { value: "no", label: "No" },
                ]}
              />
            </Field>
          </div>

          <p className="mt-5 text-xs text-neutral-400">
            Full-term ownership transfers once Total Rental Purchase Price (monthly rental × term, excl. tax) is
            paid. Early Purchase Option recalculates live per payment — see chart →
          </p>
        </div>

        <SidebarCard
          title="Lease summary"
          rows={[
            { label: "Cash price", value: money(pricing.cashPrice) },
            { label: "Term", value: `${pricing.term} mo` },
            { label: "Total rental price", value: money(pricing.totalRentalPrice) },
            { label: "Monthly total", value: money(pricing.totalMonthlyPayment) },
            { label: "Total due today", value: money(pricing.totalDueToday), highlight: true },
            { label: "EPO today (mo. 1)", value: money(pricing.epoToday), highlight: true },
          ]}
        />
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-6">
        <SectionHeading
          title="Early purchase option — payoff preview"
          subtitle="Auto-generated from cash price, monthly rental and term. Shown to the customer in the contract and portal."
        />
        <div className="mt-6">
          <EpoChart schedule={pricing.schedule} />
        </div>
      </div>
    </div>
  );
}
