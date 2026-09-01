import { Field, RadioGroup, TextArea, TextInput } from "@/components/applications/wizard/fields";
import { SidebarCard } from "@/components/applications/wizard/SidebarCard";
import { fieldError, money, num, type WizardState } from "@/components/applications/wizard/types";
import { SectionHeading } from "@/components/dashboard/SectionHeading";

export function EquipmentStep({
  state,
  set,
  fieldErrors,
}: {
  state: WizardState;
  set: <K extends keyof WizardState>(key: K, value: WizardState[K]) => void;
  fieldErrors?: Record<string, string[]>;
}) {
  const err = (key: string) => fieldError(fieldErrors, key);

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_20rem]">
      <div className="rounded-xl border border-neutral-200 bg-white p-6">
        <SectionHeading title="Equipment details" />
        <div className="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
          <Field label="Sales Person Name" error={err("sales_person")}>
            <TextInput
              value={state.salesPerson}
              onChange={(v) => set("salesPerson", v)}
              placeholder="Enter sales person's name"
              hasError={!!err("sales_person")}
            />
          </Field>
          <Field label="Cash Price / Retail" required error={err("cash_price")}>
            <TextInput
              value={state.cashPrice}
              onChange={(v) => set("cashPrice", v)}
              placeholder="0.00"
              type="number"
              hasError={!!err("cash_price")}
            />
          </Field>

          <Field label="Condition" required error={err("condition")}>
            <RadioGroup
              value={state.condition}
              onChange={(v) => set("condition", v as WizardState["condition"])}
              options={[
                { value: "new", label: "New" },
                { value: "used", label: "Used" },
              ]}
              hasError={!!err("condition")}
            />
          </Field>
          <Field label="Year" required error={err("year")}>
            <TextInput
              value={state.year}
              onChange={(v) => set("year", v)}
              placeholder="2026"
              type="number"
              hasError={!!err("year")}
            />
          </Field>

          <Field label="Make" required error={err("make")}>
            <TextInput
              value={state.make}
              onChange={(v) => set("make", v)}
              placeholder="Worldlawn"
              hasError={!!err("make")}
            />
          </Field>
          <Field label="Model" required error={err("model")}>
            <TextInput
              value={state.model}
              onChange={(v) => set("model", v)}
              placeholder="Model name and deck width"
              hasError={!!err("model")}
            />
          </Field>

          <Field label="Serial #" required error={err("serial")}>
            <TextInput
              value={state.serial}
              onChange={(v) => set("serial", v)}
              placeholder="Type NA if not available yet"
              hasError={!!err("serial")}
            />
          </Field>
        </div>

        <div className="mt-5">
          <Field label="Description" error={err("description")}>
            <TextArea
              value={state.description}
              onChange={(v) => set("description", v)}
              placeholder="Model # if available"
              hasError={!!err("description")}
            />
          </Field>
        </div>

        <div className="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
          <Field label="LDW (Loss Damage Waiver)" error={err("ldw")}>
            <RadioGroup
              value={state.ldw}
              onChange={(v) => set("ldw", v as WizardState["ldw"])}
              options={[
                { value: "yes", label: "Yes" },
                { value: "no", label: "No" },
              ]}
              hasError={!!err("ldw")}
            />
          </Field>
          <Field label="Promo Code" error={err("promo_code")}>
            <TextInput
              value={state.promoCode}
              onChange={(v) => set("promoCode", v)}
              placeholder="Optional"
              hasError={!!err("promo_code")}
            />
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
