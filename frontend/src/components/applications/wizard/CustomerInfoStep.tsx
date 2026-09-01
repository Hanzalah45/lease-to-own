import { Field, FileInput, SelectInput, TextInput } from "@/components/applications/wizard/fields";
import { fieldError, type WizardState } from "@/components/applications/wizard/types";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import type { AuthUser } from "@/types/auth";

export function CustomerInfoStep({
  state,
  set,
  customers,
  applyingAs,
  fieldErrors,
}: {
  state: WizardState;
  set: <K extends keyof WizardState>(key: K, value: WizardState[K]) => void;
  customers: AuthUser[];
  /** Customer self-service mode: hides the "pick a customer" selector and shows this name/email instead. */
  applyingAs?: { name: string; email: string };
  fieldErrors?: Record<string, string[]>;
}) {
  const err = (key: string) => fieldError(fieldErrors, key);

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-6">
      <SectionHeading title="Renter contact information" />
      <div className="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div className="sm:col-span-2">
          {applyingAs ? (
            <Field label="Applying As">
              <div className="rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2.5 text-sm text-neutral-700">
                {applyingAs.name} · {applyingAs.email}
              </div>
            </Field>
          ) : (
            <Field label="Registered Customer" required error={err("registered_customer_id")}>
              <SelectInput
                value={state.registeredCustomerId}
                onChange={(v) => set("registeredCustomerId", v)}
                placeholder="Select a registered customer…"
                options={customers.map((c) => ({ value: String(c.id), label: `${c.name} · ${c.email}` }))}
                hasError={!!err("registered_customer_id")}
              />
            </Field>
          )}
        </div>

        <Field label="Email" required error={err("email")}>
          <TextInput
            value={state.email}
            onChange={(v) => set("email", v)}
            type="email"
            hasError={!!err("email")}
          />
        </Field>
        <Field label="Cell Phone" required error={err("cell_phone")}>
          <TextInput
            value={state.cellPhone}
            onChange={(v) => set("cellPhone", v)}
            placeholder="(000) 000-0000"
            hasError={!!err("cell_phone")}
          />
        </Field>

        <div className="sm:col-span-2">
          <Field label="Mailing Address" required error={err("mailing_address")}>
            <TextInput
              value={state.mailingAddress}
              onChange={(v) => set("mailingAddress", v)}
              placeholder="Street address"
              hasError={!!err("mailing_address")}
            />
          </Field>
        </div>

        <Field label="City" required error={err("city")}>
          <TextInput value={state.city} onChange={(v) => set("city", v)} hasError={!!err("city")} />
        </Field>
        <Field label="State" required error={err("state")}>
          <TextInput value={state.state} onChange={(v) => set("state", v)} hasError={!!err("state")} />
        </Field>

        <Field label="Zip Code" required error={err("zip")}>
          <TextInput value={state.zip} onChange={(v) => set("zip", v)} hasError={!!err("zip")} />
        </Field>
        <Field label="Date of Birth" required error={err("date_of_birth")}>
          <TextInput value={state.dob} onChange={(v) => set("dob", v)} type="date" hasError={!!err("date_of_birth")} />
        </Field>

        <div className="sm:col-span-2">
          <Field label="Driver's License #" required error={err("drivers_license")}>
            <TextInput value={state.driversLicense} onChange={(v) => set("driversLicense", v)} hasError={!!err("drivers_license")} />
          </Field>
        </div>

        <div className="sm:col-span-2">
          <Field label="Upload D.L. or Gov't ID" required error={err("id_document")}>
            <FileInput value={state.idDocument} onChange={(file) => set("idDocument", file)} hasError={!!err("id_document")} />
          </Field>
        </div>
      </div>
    </div>
  );
}
