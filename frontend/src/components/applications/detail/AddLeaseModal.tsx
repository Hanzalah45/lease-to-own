"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { EquipmentStep } from "@/components/applications/wizard/EquipmentStep";
import { LeaseDetailsStep } from "@/components/applications/wizard/LeaseDetailsStep";
import {
  FIELD_TO_STEP,
  INITIAL_WIZARD_STATE,
  STATE_TO_FIELD,
  validateEquipmentStep,
  validateLeaseStep,
  type WizardState,
} from "@/components/applications/wizard/types";
import { attachLeaseToApplication } from "@/lib/applications";
import { ApiError } from "@/lib/api";
import type { Application } from "@/types/application";

const STEPS = [
  { key: "equipment", label: "1 · Equipment" },
  { key: "lease", label: "2 · Pricing" },
] as const;

type ModalStepKey = (typeof STEPS)[number]["key"];

/**
 * Rejoins a guest-originated application (submitted with no equipment or
 * price) with the normal lease-creation flow — reuses the same
 * EquipmentStep/LeaseDetailsStep the New Application wizard uses.
 */
export function AddLeaseModal({
  applicationId,
  onClose,
  onSaved,
}: {
  applicationId: number;
  onClose: () => void;
  onSaved: (application: Application) => void;
}) {
  const [step, setStep] = useState<ModalStepKey>("equipment");
  const [state, setState] = useState<WizardState>(INITIAL_WIZARD_STATE);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  const [apiErrors, setApiErrors] = useState<Record<string, string[]>>({});
  const [touchedSteps, setTouchedSteps] = useState<Record<ModalStepKey, boolean>>({
    equipment: false,
    lease: false,
  });
  const [touchedFields, setTouchedFields] = useState<Record<string, boolean>>({});

  function set<K extends keyof WizardState>(key: K, value: WizardState[K]) {
    setState((s) => ({ ...s, [key]: value }));
    const fieldName = STATE_TO_FIELD[key];
    if (fieldName) {
      setTouchedFields((prev) => ({ ...prev, [fieldName]: true }));
      if (apiErrors[fieldName]) {
        setApiErrors((prev) => {
          const next = { ...prev };
          delete next[fieldName];
          return next;
        });
      }
    }
  }

  // Re-validates every render so a field's error clears the moment it becomes
  // valid, instead of staying stuck on whatever was wrong at the last Next/Submit click.
  const allCurrentErrors = { ...validateEquipmentStep(state), ...validateLeaseStep(state) };
  const activeErrors: Record<string, string[]> = {};
  for (const fieldName of Object.keys(FIELD_TO_STEP)) {
    const fieldStep = FIELD_TO_STEP[fieldName];
    if (fieldStep !== "equipment" && fieldStep !== "lease") continue;
    const isTouched = touchedFields[fieldName] || touchedSteps[fieldStep];

    if (allCurrentErrors[fieldName] && isTouched) {
      activeErrors[fieldName] = allCurrentErrors[fieldName];
    } else if (apiErrors[fieldName]) {
      activeErrors[fieldName] = apiErrors[fieldName];
    }
  }

  function goNext() {
    const errors = validateEquipmentStep(state);
    if (Object.keys(errors).length > 0) {
      setTouchedSteps((prev) => ({ ...prev, equipment: true }));
      return;
    }
    setStep("lease");
  }

  async function submit() {
    const equipmentErrors = validateEquipmentStep(state);
    const leaseErrors = validateLeaseStep(state);
    const errors = { ...equipmentErrors, ...leaseErrors };
    if (Object.keys(errors).length > 0) {
      setTouchedSteps({ equipment: true, lease: true });
      if (Object.keys(equipmentErrors).length > 0) setStep("equipment");
      return;
    }

    setSubmitting(true);
    setSubmitError(null);
    setApiErrors({});
    try {
      const updated = await attachLeaseToApplication(applicationId, state);
      onSaved(updated);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setApiErrors(err.errors);
      } else {
        setSubmitError(err instanceof ApiError ? err.message : "Could not save equipment and pricing.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal title="Add Equipment & Pricing" onClose={onClose} maxWidthClassName="max-w-4xl">
      <div className="space-y-5">
        <p className="text-sm text-neutral-500">
          This application came in through the guest application link with no equipment or price yet. Fill these in
          once you&rsquo;ve confirmed them with the customer.
        </p>

        <div className="grid grid-cols-2 gap-0 overflow-hidden rounded-md bg-neutral-100">
          {STEPS.map((s) => (
            <button
              key={s.key}
              onClick={() => setStep(s.key)}
              className={`font-heading px-4 py-3 text-xs font-bold uppercase tracking-wide transition ${
                step === s.key ? "bg-red-600 text-white" : "text-neutral-500 hover:bg-neutral-200"
              }`}
            >
              {s.label}
            </button>
          ))}
        </div>

        {step === "equipment" && <EquipmentStep state={state} set={set} fieldErrors={activeErrors} />}
        {step === "lease" && <LeaseDetailsStep state={state} set={set} fieldErrors={activeErrors} />}

        {submitError && <p className="text-sm text-red-600">{submitError}</p>}

        <div className="flex items-center justify-between">
          <button
            onClick={onClose}
            disabled={submitting}
            className="font-heading rounded-md border border-neutral-300 px-4 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
          >
            Cancel
          </button>
          {step === "equipment" ? (
            <button
              onClick={goNext}
              className="font-heading rounded-md bg-red-600 px-5 py-2 text-sm font-bold text-white hover:bg-red-700"
            >
              Next →
            </button>
          ) : (
            <button
              onClick={submit}
              disabled={submitting}
              className="font-heading rounded-md bg-red-600 px-5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-60"
            >
              {submitting ? "Saving…" : "Save & Create Lease"}
            </button>
          )}
        </div>
      </div>
    </Modal>
  );
}
