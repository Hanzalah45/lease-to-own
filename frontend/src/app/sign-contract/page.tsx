"use client";

import Image from "next/image";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { money, TRACKING_DEVICE_FEE } from "@/components/applications/wizard/types";
import { getSignedLease, signLeaseViaLink, type SignedContractLinkParams } from "@/lib/contracts";
import { ApiError } from "@/lib/api";
import { validateName } from "@/lib/validation";
import { CheckCircleIcon } from "@/components/icons";
import type { LeaseAgreement } from "@/types/lease-agreement";

function num(value: string | number | null | undefined): number {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n : 0;
}

/**
 * Public counterpart to the customer portal's contract-signing step —
 * reached from the signed link RequestContractSignatureNotification emails
 * once an application reaches "waiting on deposit" (see ContractSigner on
 * the backend). Exists because a guest-originated customer's account has no
 * usable password yet (activated at first-payment/pickup, which happens
 * AFTER signing in the confirmed flow), so they can't log in to reach
 * /customer/contracts/[id]/sign.
 */
export default function SignContractPage() {
  return (
    <Suspense fallback={null}>
      <SignContractFlow />
    </Suspense>
  );
}

function SignContractFlow() {
  const searchParams = useSearchParams();
  const id = searchParams.get("id");
  const lease = searchParams.get("lease");
  const hash = searchParams.get("hash");
  const expires = searchParams.get("expires");
  const signature = searchParams.get("signature");
  const hasAllParams = Boolean(id && lease && hash && expires && signature);
  const params: SignedContractLinkParams | null = hasAllParams
    ? { id: id!, lease: lease!, hash: hash!, expires: expires!, signature: signature! }
    : null;

  const [leaseAgreement, setLeaseAgreement] = useState<LeaseAgreement | null>(null);
  const [loading, setLoading] = useState(hasAllParams);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [agreed, setAgreed] = useState(false);
  const [agreedTouched, setAgreedTouched] = useState(false);
  const [typedName, setTypedName] = useState("");
  const [nameTouched, setNameTouched] = useState(false);
  const [signing, setSigning] = useState(false);
  const [signError, setSignError] = useState<string | null>(null);

  useEffect(() => {
    if (!params) return;
    getSignedLease(params)
      .then(setLeaseAgreement)
      .catch((err) => setLoadError(err instanceof ApiError ? err.message : "This signing link is invalid or has expired."))
      .finally(() => setLoading(false));
    // Runs once on mount with whatever the URL carried.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const nameError = validateName(typedName, "Full legal name");
  const isValid = agreed && !nameError;

  async function handleSign() {
    if (!leaseAgreement || !params) return;
    if (!isValid) {
      setNameTouched(true);
      setAgreedTouched(true);
      return;
    }
    setSigning(true);
    setSignError(null);
    try {
      await signLeaseViaLink(params, typedName.trim());
      setLeaseAgreement(await getSignedLease(params));
    } catch (err) {
      setSignError(err instanceof ApiError ? err.message : "Could not sign the agreement. Please try again.");
    } finally {
      setSigning(false);
    }
  }

  const totalMonthly = num(leaseAgreement?.total_monthly_payment);
  const totalDueToday = num(leaseAgreement?.security_deposit) + TRACKING_DEVICE_FEE + totalMonthly;
  const signed = !!leaseAgreement?.contract;

  return (
    <main
      className="flex flex-1 justify-center p-6"
      style={{
        background:
          "radial-gradient(circle at 15% 20%, rgba(220,38,38,0.12), transparent 45%), radial-gradient(circle at 85% 75%, rgba(220,38,38,0.10), transparent 45%), #fafafa",
      }}
    >
      <div className="w-full max-w-xl py-8">
        <div className="mb-6 flex flex-col items-center text-center">
          <Image src="/logo.png" alt="Outdoor Fix" width={159} height={103} className="mb-3 h-16 w-auto" priority />
          <p className="font-heading text-xs font-semibold uppercase tracking-widest text-neutral-400">Outdoor Fix</p>
          <h1 className="mt-1 text-xl font-bold uppercase tracking-tight text-neutral-900">Sign your lease agreement</h1>
          <p className="mt-1 text-sm text-neutral-500">Review the terms below, then sign to complete your lease.</p>
        </div>

        {!hasAllParams ? (
          <div className="rounded-2xl bg-white p-8 text-center shadow-xl shadow-black/5">
            <p className="text-sm text-red-600">This signing link is incomplete.</p>
          </div>
        ) : loading ? (
          <div className="rounded-2xl bg-white p-8 text-center shadow-xl shadow-black/5">
            <p className="text-sm text-neutral-400">Checking link…</p>
          </div>
        ) : loadError || !leaseAgreement ? (
          <div className="rounded-2xl bg-white p-8 text-center shadow-xl shadow-black/5">
            <p className="text-sm text-red-600">{loadError ?? "Lease not found."}</p>
          </div>
        ) : (
          <div className="space-y-5">
            <div className="rounded-xl border border-neutral-200 bg-white p-5">
              <div className="mb-4 flex items-center gap-2">
                <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
                <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Agreement summary</h2>
              </div>
              <div className="space-y-2.5 text-sm">
                <div className="flex items-center justify-between border-b border-neutral-100 py-1">
                  <span className="text-neutral-500">Equipment</span>
                  <span className="font-semibold text-neutral-900">{leaseAgreement.equipment_unit?.model ?? "—"}</span>
                </div>
                <div className="flex items-center justify-between border-b border-neutral-100 py-1">
                  <span className="text-neutral-500">Term</span>
                  <span className="font-semibold text-neutral-900">{leaseAgreement.term_months} months</span>
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
              <div className="flex items-start gap-3 rounded-xl border border-green-200 bg-green-50 p-5">
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-600">
                  <CheckCircleIcon className="h-4 w-4" />
                </span>
                <div>
                  <p className="text-sm font-bold text-green-700">Signed &amp; legally valid</p>
                  <p className="mt-1 text-sm text-neutral-600">
                    Signed on {new Date(leaseAgreement.contract!.signed_at).toLocaleString()}.
                  </p>
                  <p className="mt-3 text-xs text-neutral-400">
                    You&rsquo;re all set. Outdoor Fix will be in touch with next steps for pickup.
                  </p>
                </div>
              </div>
            ) : (
              <div className="rounded-xl border border-neutral-200 bg-white p-5">
                <div className="mb-4 flex items-center gap-2">
                  <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
                  <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Signature</h2>
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
                    I have read and agree to the Lease Purchase Agreement, Early Purchase Option terms, and AutoPay
                    Payment Authorization.
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
            )}
          </div>
        )}

        <div className="mt-6 text-center text-sm text-neutral-500">
          <Link href="/login" className="font-semibold text-neutral-900 underline">
            Back to sign in
          </Link>
        </div>
      </div>
    </main>
  );
}
