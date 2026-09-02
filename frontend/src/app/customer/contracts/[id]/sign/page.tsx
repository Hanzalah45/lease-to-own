"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useAuth } from "@/context/AuthContext";
import { money } from "@/components/applications/wizard/types";
import { getMyLeaseAgreement } from "@/lib/lease-agreements";
import { signLease } from "@/lib/contracts";
import { ApiError } from "@/lib/api";
import { validateName } from "@/lib/validation";
import type { LeaseAgreement } from "@/types/lease-agreement";

function num(value: string | number | null | undefined): number {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n : 0;
}

export default function SignLeaseAgreementPage() {
  const params = useParams<{ id: string }>();
  const { user } = useAuth();
  const [lease, setLease] = useState<LeaseAgreement | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [agreed, setAgreed] = useState(false);
  const [typedName, setTypedName] = useState("");
  const [nameTouched, setNameTouched] = useState(false);
  const [signing, setSigning] = useState(false);
  const [signError, setSignError] = useState<string | null>(null);

  useEffect(() => {
    getMyLeaseAgreement(params.id)
      .then(setLease)
      .catch((err) => setLoadError(err instanceof ApiError ? err.message : "Could not load this lease."))
      .finally(() => setLoading(false));
  }, [params.id]);

  const nameError = validateName(typedName, "Full legal name");
  const canSign = agreed && !nameError && !signing;

  async function handleSign() {
    if (!lease) return;
    setSigning(true);
    setSignError(null);
    try {
      await signLease(lease.id);
      setLease(await getMyLeaseAgreement(lease.id));
    } catch (err) {
      setSignError(err instanceof ApiError ? err.message : "Could not sign the agreement. Please try again.");
    } finally {
      setSigning(false);
    }
  }

  if (loading) return <p className="text-sm text-neutral-500">Loading…</p>;
  if (loadError || !lease) return <p className="text-sm text-red-600">{loadError ?? "Lease not found."}</p>;

  const totalMonthly = num(lease.total_monthly_payment);
  const totalDueToday = num(lease.security_deposit) + totalMonthly;
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
            <span className="font-semibold text-neutral-900">{money(totalMonthly)}</span>
          </div>
          <div className="flex items-center justify-between py-1">
            <span className="text-neutral-500">Total due today</span>
            <span className="font-semibold text-neutral-900">{money(totalDueToday)}</span>
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
        <div className="rounded-xl border border-neutral-200 bg-white p-5">
          <div className="mb-4 flex items-center gap-2">
            <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
            <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Signature</h2>
          </div>

          <label className="mb-4 flex items-start gap-2.5 text-sm text-neutral-700">
            <input
              type="checkbox"
              checked={agreed}
              onChange={(e) => setAgreed(e.target.checked)}
              className="mt-0.5 h-4 w-4 accent-red-600"
            />
            I have read and agree to the Lease Purchase Agreement, Early Purchase Option terms, and AutoPay Payment
            Authorization.
          </label>

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
            disabled={!canSign}
            className="font-heading mt-4 w-full rounded-md bg-red-600 py-3 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40"
          >
            {signing ? "Signing…" : "Sign & Complete →"}
          </button>
        </div>
      )}
    </div>
  );
}
