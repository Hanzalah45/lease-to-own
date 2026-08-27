import { Field, RadioGroup, TextArea, TextInput } from "@/components/applications/wizard/fields";
import { SidebarCard } from "@/components/applications/wizard/SidebarCard";
import { money, num, type WizardState } from "@/components/applications/wizard/types";
import { SectionHeading } from "@/components/dashboard/SectionHeading";

export function EquipmentStep({
  state,
  set,
}: {
  state: WizardState;
  set: <K extends keyof WizardState>(key: K, value: WizardState[K]) => void;
}) {
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_20rem]">
      <div className="rounded-xl border border-neutral-200 bg-white p-6">
        <SectionHeading title="Equipment details" />
        <div className="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
          <Field label="Sales Person Name">
            <TextInput
              value={state.salesPerson}
              onChange={(v) => set("salesPerson", v)}
              placeholder="Enter sales person's name"
            />
          </Field>
          <Field label="Cash Price / Retail" required>
            <TextInput value={state.cashPrice} onChange={(v) => set("cashPrice", v)} placeholder="0.00" type="number" />
          </Field>

          <Field label="Condition" required>
            <RadioGroup
              value={state.condition}
              onChange={(v) => set("condition", v as WizardState["condition"])}
              options={[
                { value: "new", label: "New" },
                { value: "used", label: "Used" },
              ]}
            />
          </Field>
          <Field label="Year" required>
            <TextInput value={state.year} onChange={(v) => set("year", v)} placeholder="2026" type="number" />
          </Field>

          <Field label="Make" required>
            <TextInput value={state.make} onChange={(v) => set("make", v)} placeholder="Worldlawn" />
          </Field>
          <Field label="Model" required>
            <TextInput value={state.model} onChange={(v) => set("model", v)} placeholder="Model name and deck width" />
          </Field>

          <Field label="Serial #" required>
            <TextInput value={state.serial} onChange={(v) => set("serial", v)} placeholder="Type NA if not available yet" />
          </Field>
        </div>

        <div className="mt-5">
          <Field label="Description">
            <TextArea value={state.description} onChange={(v) => set("description", v)} placeholder="Model # if available" />
          </Field>
        </div>

        <div className="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
          <Field label="LDW (Loss Damage Waiver)">
            <RadioGroup
              value={state.ldw}
              onChange={(v) => set("ldw", v as WizardState["ldw"])}
              options={[
                { value: "yes", label: "Yes" },
                { value: "no", label: "No" },
              ]}
            />
          </Field>
          <Field label="Promo Code">
            <TextInput value={state.promoCode} onChange={(v) => set("promoCode", v)} placeholder="Optional" />
          </Field>
        </div>
      </div>

      <SidebarCard
        title="Product info"
        rows={[
          { label: "Customer ZIP", value: state.zip || "—" },
          { label: "State", value: state.state || "—" },
          { label: "Dealer", value: "Outdoor Fix" },
          { label: "Product type", value: "Mower" },
          { label: "Cash price", value: money(num(state.cashPrice)) },
          { label: "Tax rate", value: `${state.taxRate || "0"}%` },
        ]}
      />
    </div>
  );
}
