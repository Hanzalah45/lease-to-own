"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useAuth } from "@/context/AuthContext";
import { money, TRACKING_DEVICE_FEE } from "@/components/applications/wizard/types";
import { getMyLeaseAgreement } from "@/lib/lease-agreements";
import { previewLease, signLease } from "@/lib/contracts";
import { ApiError } from "@/lib/api";
import { isBeforeToday } from "@/lib/dates";
import { validateName } from "@/lib/validation";
import { BillingCycleCard } from "@/components/contracts/BillingCycleCard";
import type { BillingCycle, LeaseAgreement } from "@/types/lease-agreement";

function num(value: string | number | null | undefined): number {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n : 0;
}

export default function SignLeaseAgreementPage() {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const { user } = useAuth();
  const [lease, setLease] = useState<LeaseAgreement | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [agreed, setAgreed] = useState(false);
  const [agreedTouched, setAgreedTouched] = useState(false);
  const [typedName, setTypedName] = useState("");
  const [nameTouched, setNameTouched] = useState(false);
  const [signing, setSigning] = useState(false);
  const [signError, setSignError] = useState<string | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [previewError, setPreviewError] = useState<string | null>(null);
  // Billing cycle (client, 2026-10-05): picked right before signing so the
  // contract states it; pre-selected if an admin already set one.
  const [billingCycle, setBillingCycle] = useState<BillingCycle | null>(null);
  const [cycleTouched, setCycleTouched] = useState(false);

  async function handlePreview() {
    if (!lease) return;
    setPreviewing(true);
    setPreviewError(null);
    try {
      await previewLease(lease.id, billingCycle ?? undefined);
    } catch (err) {
      setPreviewError(err instanceof ApiError ? err.message : "Could not load the agreement.");
    } finally {
      setPreviewing(false);
    }
  }

  useEffect(() => {
    getMyLeaseAgreement(params.id)
      .then((loaded) => {
        setLease(loaded);
        // After pickup, a pre-selected billing day whose next payment date has already passed cannot be signed.
        const preselected = loaded.billing_cycle ? loaded.billing_preview?.[loaded.billing_cycle] : undefined;
        const unusable = !!loaded.billing_preview_pickup_date && !!preselected && isBeforeToday(preselected.second_payment_date);
        setBillingCycle(unusable ? null : loaded.billing_cycle);
      })
      .catch((err) => setLoadError(err instanceof ApiError ? err.message : "Could not load this lease."))
      .finally(() => setLoading(false));
  }, [params.id]);

  const nameError = validateName(typedName, "Full legal name");
  const cycleError = billingCycle ? null : "Choose your billing day before signing.";
  const isValid = agreed && !nameError && !cycleError;

  async function handleSign() {
    if (!lease) return;
    if (!isValid) {
      setNameTouched(true);
      setAgreedTouched(true);
      setCycleTouched(true);
      return;
    }
    setSigning(true);
    setSignError(null);
    try {
      await signLease(lease.id, typedName.trim(), billingCycle ?? undefined);
      // Automatically continues into AutoPay setup (client, Joel, 2026-10-02)
      // — no further email/link needed, the customer is already logged in.
      router.push(`/customer/leases/${lease.id}/autopay`);
    } catch (err) {
      setSignError(err instanceof ApiError ? err.message : "Could not sign the agreement. Please try again.");
    } finally {
      setSigning(false);
    }
  }

  if (loading) return <p className="text-sm text-neutral-500">Loading…</p>;
  if (loadError || !lease) return <p className="text-sm text-red-600">{loadError ?? "Lease not found."}</p>;

  const totalMonthly = num(lease.total_monthly_payment);
  // Both prices come from the server (dual pricing, client 2026-10-05); the old client-side sum is only a fallback.
  const pricing = lease.pricing;
  const totalDueToday = pricing?.full.bank ?? num(lease.security_deposit) + TRACKING_DEVICE_FEE + totalMonthly;
  const signed = !!lease.contract;

  return (
    <div className="space-y-6">
      <div>
        <Link href="/customer/contracts" className="font-heading text-xs font-bold uppercase tracking-wide text-red-600 hover:underline">
          ← Back
        </Link>
        <h1 className="mt-1 text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">
          Sign Your Lease Agreement
        </h1>
        <p className="text-sm text-neutral-400">Review the terms below, then sign to complete your lease.</p>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <div className="mb-4 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Agreement summary</h2>
        </div>
        <div className="space-y-2.5 text-sm">
          <div className="flex items-center justify-between border-b border-neutral-100 py-1">
            <span className="text-neutral-500">Customer</span>
            <span className="font-semibold text-neutral-900">{user?.name}</span>
          </div>
          <div className="flex items-center justify-between border-b border-neutral-100 py-1">
            <span className="text-neutral-500">Equipment</span>
            <span className="font-semibold text-neutral-900">{lease.equipment_unit?.model ?? "—"}</span>
          </div>
          <div className="flex items-center justify-between border-b border-neutral-100 py-1">
            <span className="text-neutral-500">Term</span>
            <span className="font-semibold text-neutral-900">{lease.term_months} months</span>
          </div>
          <div className="flex items-center justify-between border-b border-neutral-100 py-1">
            <span className="text-neutral-500">Total monthly payment</span>
            <span className="text-right font-semibold text-neutral-900">
              {money(totalMonthly)} by bank
              {pricing && <span className="block text-xs font-normal text-neutral-500">{money(pricing.monthly.card)} by card</span>}
            </span>
          </div>
          <div className="flex items-center justify-between py-1">
            <span className="text-neutral-500">Total due today</span>
            <span className="text-right font-semibold text-neutral-900">
              {money(totalDueToday)} by bank
              {pricing && <span className="block text-xs font-normal text-neutral-500">{money(pricing.full.card)} by card</span>}
            </span>
          </div>
        </div>
      </div>

      {signed ? (
        <div className="rounded-xl border border-green-200 bg-green-50 p-5">
          <p className="text-sm font-bold text-green-700">Signed &amp; legally valid</p>
          <p className="mt-1 text-sm text-neutral-600">
            Signed by {user?.name} on {new Date(lease.contract!.signed_at).toLocaleString()}.
          </p>
          <Link
            href={`/customer/contracts/${lease.id}/document`}
            className="font-heading mt-4 inline-block rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
          >
            View signed document →
          </Link>
        </div>
      ) : (
        <>
        <BillingCycleCard
          preview={lease.billing_preview}
          pickupDate={lease.billing_preview_pickup_date}
          value={billingCycle}
          onChange={(cycle) => {
            setBillingCycle(cycle);
            setCycleTouched(true);
          }}
          error={cycleTouched ? cycleError : null}
        />
        <div className="rounded-xl border border-neutral-200 bg-white p-5">
          <div className="mb-4 flex items-center gap-2">
            <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
            <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Signature</h2>
          </div>

          <div className="mb-4 rounded-md border border-neutral-200 bg-neutral-50 p-3.5 text-center">
            <button
              onClick={handlePreview}
              disabled={previewing}
              className="font-heading text-sm font-bold text-red-600 underline hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {previewing ? "Opening…" : "View full lease agreement →"}
            </button>
            <p className="mt-1 text-xs text-neutral-400">Opens the complete document in a new tab.</p>
            {previewError && <p className="mt-1.5 text-xs text-red-600">{previewError}</p>}
          </div>

          <div className="mb-4">
            <label className="flex items-start gap-2.5 text-sm text-neutral-700">
              <input
                type="checkbox"
                checked={agreed}
                onChange={(e) => {
                  setAgreed(e.target.checked);
                  setAgreedTouched(true);
                }}
                aria-invalid={agreedTouched && !agreed}
                className="mt-0.5 h-4 w-4 accent-red-600"
              />
              I have read and agree to the Lease Purchase Agreement, Early Purchase Option terms, and AutoPay Payment
              Authorization.
            </label>
            {agreedTouched && !agreed && (
              <p className="mt-1.5 text-xs text-red-600">You must agree to the terms before signing.</p>
            )}
          </div>

          <div className="rounded-md border border-dashed border-neutral-300 p-6 text-center">
            <input
              value={typedName}
              onChange={(e) => setTypedName(e.target.value)}
              onBlur={() => setNameTouched(true)}
              placeholder="Type your full legal name"
              aria-label="Full legal name"
              aria-invalid={nameTouched && !!nameError}
              className={`w-full border-b bg-transparent pb-2 text-center font-serif text-2xl italic text-neutral-700 placeholder:text-neutral-300 focus:outline-none ${
                nameTouched && nameError ? "border-red-500" : "border-neutral-900"
              }`}
            />
            {nameTouched && nameError && <p className="mt-2 text-xs text-red-600">{nameError}</p>}
            <p className="mt-3 text-xs text-neutral-400">
              Typing your name above and clicking Sign constitutes your legal electronic signature.
            </p>
          </div>

          {signError && <p className="mt-3 text-sm text-red-600">{signError}</p>}

          <button
            onClick={handleSign}
            disabled={signing}
            className="font-heading mt-4 w-full rounded-md bg-red-600 py-3 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40"
          >
            {signing ? "Signing…" : "Sign & Complete →"}
          </button>
        </div>
        </>
      )}
    </div>
  );
}
