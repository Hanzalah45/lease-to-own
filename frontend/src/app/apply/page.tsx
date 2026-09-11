"use client";

import Image from "next/image";
import Link from "next/link";
import { useState } from "react";
import { CustomerInfoStep } from "@/components/applications/wizard/CustomerInfoStep";
import { RiskVerificationStep } from "@/components/applications/wizard/RiskVerificationStep";
import {
  FIELD_TO_STEP,
  INITIAL_WIZARD_STATE,
  STATE_TO_FIELD,
  validateAllSteps,
  validateStep,
  type WizardState,
} from "@/components/applications/wizard/types";
import { submitGuestApplication } from "@/lib/applications";
import { ApiError } from "@/lib/api";

const GUEST_STEPS = [
  { key: "customer", label: "1 · Your Info" },
  { key: "risk", label: "2 · Residence & Verification" },
] as const;

type GuestStepKey = (typeof GUEST_STEPS)[number]["key"];

/**
 * Public, no-login application (client requirement, 2026-09-04): one
 * generic link, shared with anyone, no account needed until the first
 * payment/pickup. Reuses the same CustomerInfoStep/RiskVerificationStep
 * components the logged-in wizards use — just without the equipment/lease
 * steps, since nothing is priced yet, and with guestMode on so name/email
 * are collected directly instead of being read from an existing account.
 */
export default function GuestApplicationPage() {
  const [step, setStep] = useState<GuestStepKey>("customer");
  const [state, setState] = useState<WizardState>(INITIAL_WIZARD_STATE);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState(false);

  const [apiErrors, setApiErrors] = useState<Record<string, string[]>>({});
  const [touchedSteps, setTouchedSteps] = useState<Record<GuestStepKey, boolean>>({ customer: false, risk: false });
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

  const allCurrentErrors = validateAllSteps(state, false, true);
  const activeErrors: Record<string, string[]> = {};
  for (const fieldName of Object.keys(FIELD_TO_STEP)) {
    const fieldStep = FIELD_TO_STEP[fieldName];
    if (fieldStep !== "customer" && fieldStep !== "risk") continue;
    const isTouched = touchedFields[fieldName] || touchedSteps[fieldStep];

    if (allCurrentErrors[fieldName] && isTouched) {
      activeErrors[fieldName] = allCurrentErrors[fieldName];
    } else if (apiErrors[fieldName]) {
      activeErrors[fieldName] = apiErrors[fieldName];
    }
  }

  const index = GUEST_STEPS.findIndex((s) => s.key === step);
  const isFirst = index === 0;
  const isLast = index === GUEST_STEPS.length - 1;

  function goNext() {
    if (isLast) return;
    const currentStepErrors = validateStep(step, state, false, true);
    if (Object.keys(currentStepErrors).length > 0) {
      setTouchedSteps((prev) => ({ ...prev, [step]: true }));
      return;
    }
    setStep(GUEST_STEPS[index + 1].key);
  }

  function goBack() {
    if (!isFirst) setStep(GUEST_STEPS[index - 1].key);
  }

  async function submit() {
    setSubmitError(null);
    setApiErrors({});

    const errors = validateAllSteps(state, false, true);
    if (Object.keys(errors).length > 0) {
      setTouchedSteps({ customer: true, risk: true });
      const jumpTo = FIELD_TO_STEP[Object.keys(errors)[0]];
      if (jumpTo === "customer" || jumpTo === "risk") setStep(jumpTo);
      return;
    }

    setSubmitting(true);
    try {
      await submitGuestApplication(state);
      setSubmitted(true);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setApiErrors(err.errors);
        const jumpTo = FIELD_TO_STEP[Object.keys(err.errors)[0]];
        if (jumpTo === "customer" || jumpTo === "risk") setStep(jumpTo);
      } else {
        setSubmitError(err instanceof ApiError ? err.message : "Could not submit the application. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main
      className="flex flex-1 justify-center p-6"
      style={{
        background:
          "radial-gradient(circle at 15% 20%, rgba(220,38,38,0.12), transparent 45%), radial-gradient(circle at 85% 75%, rgba(220,38,38,0.10), transparent 45%), #fafafa",
      }}
    >
      <div className="w-full max-w-3xl py-8">
        <div className="mb-6 flex flex-col items-center text-center">
          <Image src="/logo.png" alt="Prostart Leasing" width={159} height={103} className="mb-3 h-16 w-auto" priority />
          <h1 className="text-2xl font-bold uppercase tracking-tight text-neutral-900">Lease Application</h1>
          <p className="mt-1 text-sm text-neutral-500">
            Tell us a bit about yourself. A Prostart Leasing rep will follow up to finish setting up your lease.
          </p>
        </div>

        {submitted ? (
          <div className="rounded-2xl bg-white p-8 text-center shadow-xl shadow-black/5">
            <p className="text-lg font-bold text-neutral-900">Application submitted</p>
            <p className="mt-2 text-sm text-neutral-500">
              Thanks! Someone from Prostart Leasing will reach out shortly to go over pricing and next steps.
            </p>
            <Link href="/login" className="mt-6 inline-block font-heading text-sm font-bold text-red-600 hover:underline">
              Already have an account? Sign in →
            </Link>
          </div>
        ) : (
          <>
            <div className="mb-6 grid grid-cols-2 gap-0 overflow-hidden rounded-md bg-neutral-100">
              {GUEST_STEPS.map((s) => (
                <button
                  key={s.key}
                  onClick={() => setStep(s.key)}
                  className={`font-heading px-4 py-3 text-xs font-bold uppercase tracking-wide transition sm:text-sm ${
                    step === s.key ? "bg-red-600 text-white" : "text-neutral-500 hover:bg-neutral-200"
                  }`}
                >
                  {s.label}
                </button>
              ))}
            </div>

            {step === "customer" && (
              <CustomerInfoStep state={state} set={set} customers={[]} guestMode fieldErrors={activeErrors} />
            )}
            {step === "risk" && <RiskVerificationStep state={state} set={set} fieldErrors={activeErrors} />}

            <div className="mt-6 flex items-center justify-between">
              {isFirst ? (
                <Link
                  href="/login"
                  className="font-heading rounded-md border border-red-600 px-6 py-2.5 text-sm font-bold text-red-600 hover:bg-red-50"
                >
                  ← Sign in instead
                </Link>
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
          </>
        )}
      </div>
    </main>
  );
}
