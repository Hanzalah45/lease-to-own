"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/context/AuthContext";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { WizardSteps, STEPS, type StepKey } from "@/components/applications/wizard/WizardSteps";
import { EquipmentStep } from "@/components/applications/wizard/EquipmentStep";
import { LeaseDetailsStep } from "@/components/applications/wizard/LeaseDetailsStep";
import { CustomerInfoStep } from "@/components/applications/wizard/CustomerInfoStep";
import { RiskVerificationStep } from "@/components/applications/wizard/RiskVerificationStep";
import {
  FIELD_TO_STEP,
  firstErrorStep,
  INITIAL_WIZARD_STATE,
  STATE_TO_FIELD,
  validateAllSteps,
  validateStep,
  type WizardState,
} from "@/components/applications/wizard/types";
import { createMyApplication } from "@/lib/applications";
import { ApiError } from "@/lib/api";

export default function NewCustomerApplicationPage() {
  const router = useRouter();
  const { user } = useAuth();
  const [step, setStep] = useState<StepKey>("equipment");
  const [state, setState] = useState<WizardState>(() => ({
    ...INITIAL_WIZARD_STATE,
    email: user?.email ?? "",
    cellPhone: user?.phone ?? "",
  }));
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  const [apiErrors, setApiErrors] = useState<Record<string, string[]>>({});
  const [touchedSteps, setTouchedSteps] = useState<Record<StepKey, boolean>>({
    equipment: false,
    lease: false,
    customer: false,
    risk: false,
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

  // Compute live validation errors (isCustomerApp = true)
  const allCurrentErrors = validateAllSteps(state, true);

  // Active errors map passed to components
  const activeErrors: Record<string, string[]> = {};
  for (const fieldName of Object.keys(FIELD_TO_STEP)) {
    const fieldStep = FIELD_TO_STEP[fieldName];
    const isTouched = touchedFields[fieldName] || touchedSteps[fieldStep];

    if (allCurrentErrors[fieldName] && isTouched) {
      activeErrors[fieldName] = allCurrentErrors[fieldName];
    } else if (apiErrors[fieldName]) {
      activeErrors[fieldName] = apiErrors[fieldName];
    }
  }

  const index = STEPS.findIndex((s) => s.key === step);
  const isFirst = index === 0;
  const isLast = index === STEPS.length - 1;

  function handleSelectStep(targetKey: StepKey) {
    const targetIndex = STEPS.findIndex((s) => s.key === targetKey);

    if (targetIndex > index) {
      for (let i = 0; i <= targetIndex - 1; i++) {
        const stepKey = STEPS[i].key;
        const stepErrors = validateStep(stepKey, state, true);
        if (Object.keys(stepErrors).length > 0) {
          setTouchedSteps((prev) => ({ ...prev, [stepKey]: true }));
          setStep(stepKey);
          return;
        }
      }
    }

    setStep(targetKey);
  }

  function goNext() {
    if (isLast) return;
    const currentStepErrors = validateStep(step, state, true);
    if (Object.keys(currentStepErrors).length > 0) {
      setTouchedSteps((prev) => ({ ...prev, [step]: true }));
      return;
    }
    setStep(STEPS[index + 1].key);
  }

  function goBack() {
    if (!isFirst) setStep(STEPS[index - 1].key);
  }

  async function submit() {
    setSubmitError(null);
    setApiErrors({});

    const errors = validateAllSteps(state, true);
    if (Object.keys(errors).length > 0) {
      setTouchedSteps({
        equipment: true,
        lease: true,
        customer: true,
        risk: true,
      });
      const jumpTo = firstErrorStep(errors);
      if (jumpTo) setStep(jumpTo);
      return;
    }

    setSubmitting(true);
    try {
      const application = await createMyApplication(state);
      router.push(`/customer/applications/${application.id}`);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setApiErrors(err.errors);
        const jumpTo = firstErrorStep(err.errors);
        if (jumpTo) setStep(jumpTo);
      } else {
        setSubmitError(err instanceof ApiError ? err.message : "Could not submit the application. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-6">
      <PageHeroHeader title="New lease application" subtitle="Tell us about the equipment you'd like to lease. An Outdoor Fix rep will review it.">
        <WizardSteps active={step} onSelect={handleSelectStep} />
      </PageHeroHeader>

      {step === "equipment" && <EquipmentStep state={state} set={set} fieldErrors={activeErrors} />}
      {step === "lease" && <LeaseDetailsStep state={state} set={set} fieldErrors={activeErrors} />}
      {step === "customer" && (
        <CustomerInfoStep
          state={state}
          set={set}
          customers={[]}
          applyingAs={{ name: user?.name ?? "", email: user?.email ?? "" }}
          fieldErrors={activeErrors}
        />
      )}
      {step === "risk" && <RiskVerificationStep state={state} set={set} fieldErrors={activeErrors} />}

      <div className="flex items-center justify-between">
        {isFirst ? (
          <button
            onClick={() => router.push("/customer/applications")}
            className="font-heading rounded-md border border-red-600 px-6 py-2.5 text-sm font-bold text-red-600 hover:bg-red-50"
          >
            ← Cancel
          </button>
        ) : (
          <button
            onClick={goBack}
            className="font-heading rounded-md border border-red-600 px-6 py-2.5 text-sm font-bold text-red-600 hover:bg-red-50"
          >
            ← Back
          </button>
        )}

        {isLast ? (
          <div className="flex flex-col items-end gap-2">
            {submitError && <p className="text-xs font-semibold text-red-600">{submitError}</p>}
            <button
              onClick={submit}
              disabled={submitting}
              className="font-heading rounded-md bg-red-600 px-6 py-2.5 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-60"
            >
              {submitting ? "Submitting…" : "Submit Application →"}
            </button>
          </div>
        ) : (
          <button
            onClick={goNext}
            className="font-heading rounded-md bg-red-600 px-6 py-2.5 text-sm font-bold text-white hover:bg-red-700"
          >
            Next →
          </button>
        )}
      </div>
    </div>
  );
}
