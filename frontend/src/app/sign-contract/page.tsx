"use client";

import Image from "next/image";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { money, TRACKING_DEVICE_FEE } from "@/components/applications/wizard/types";
import { getSignedLease, previewSignedLease, type SignedContractLinkParams } from "@/lib/contracts";
import { activateAccountFromLink } from "@/lib/auth";
import { ApiError } from "@/lib/api";
import { validatePassword } from "@/lib/validation";
import type { LeaseAgreement } from "@/types/lease-agreement";

function num(value: string | number | null | undefined): number {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n : 0;
}

/**
 * Consolidated guest onboarding, step 1 of 2 (client, Joel, 2026-10-02):
 * reached from the signed link RequestContractSignatureNotification emails
 * once an application reaches "waiting on deposit" (see ContractSigner on
 * the backend). Shows the agreement preview, then — as its own separate step,
 * per Joel's explicit correction ("first do the account, next sign the
 * contract") — creates the customer's account. That step issues a real
 * session, so signing itself (step 2) happens on the normal authenticated
 * /customer/contracts/[id]/sign page instead of a second guest-only UI here.
 */
export default function SignContractPage() {
  return (
    <Suspense fallback={null}>
      <SignContractFlow />
    </Suspense>
  );
}

function SignContractFlow() {
  const router = useRouter();
  const { refresh } = useAuth();
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

  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [touched, setTouched] = useState(false);
  const [activating, setActivating] = useState(false);
  const [activateError, setActivateError] = useState<string | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [previewError, setPreviewError] = useState<string | null>(null);

  async function handlePreview() {
    if (!params) return;
    setPreviewing(true);
    setPreviewError(null);
    try {
      await previewSignedLease(params);
    } catch (err) {
      setPreviewError(err instanceof ApiError ? err.message : "Could not load the agreement.");
    } finally {
      setPreviewing(false);
    }
  }

  useEffect(() => {
    if (!params) return;
    getSignedLease(params)
      .then(setLeaseAgreement)
      .catch((err) => setLoadError(err instanceof ApiError ? err.message : "This signing link is invalid or has expired."))
      .finally(() => setLoading(false));
    // Runs once on mount with whatever the URL carried.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const passwordErr = validatePassword(password, true);
  const confirmErr = passwordConfirmation && passwordConfirmation !== password ? "Passwords do not match." : undefined;
  const isValid = !passwordErr && !confirmErr && !!passwordConfirmation;

  async function handleActivate() {
    if (!params || !lease) return;
    if (!isValid) {
      setTouched(true);
      return;
    }
    setActivating(true);
    setActivateError(null);
    try {
      await activateAccountFromLink({ ...params, password, password_confirmation: passwordConfirmation });
      await refresh();
      router.push(`/customer/contracts/${lease}/sign`);
    } catch (err) {
      setActivateError(err instanceof ApiError ? err.message : "Could not create your account. Please try again.");
    } finally {
      setActivating(false);
    }
  }

  const totalMonthly = num(leaseAgreement?.total_monthly_payment);
  // Both prices come from the server (dual pricing, client 2026-10-05); the old client-side sum is only a fallback.
  const pricing = leaseAgreement?.pricing;
  const totalDueToday = pricing?.full.bank ?? num(leaseAgreement?.security_deposit) + TRACKING_DEVICE_FEE + totalMonthly;
  const accountAlreadyActive = !!leaseAgreement?.customer_account_active;

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
          <Image src="/logo.png" alt="Prostart Leasing" width={159} height={103} className="mb-3 h-16 w-auto" priority />
          <p className="font-heading text-xs font-semibold uppercase tracking-widest text-neutral-400">Prostart Leasing</p>
          <h1 className="mt-1 text-xl font-bold uppercase tracking-tight text-neutral-900">Your lease agreement</h1>
          <p className="mt-1 text-sm text-neutral-500">Review the terms below, then create your account to sign.</p>
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
                  <span className="text-right font-semibold text-neutral-900">
                    {money(totalDueToday)} by bank
                    {pricing && <span className="block text-xs font-normal text-neutral-500">{money(pricing.full.card)} by card</span>}
                  </span>
                </div>
              </div>
            </div>

            <div className="rounded-md border border-neutral-200 bg-neutral-50 p-3.5 text-center">
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

            {accountAlreadyActive ? (
              <div className="rounded-xl border border-neutral-200 bg-white p-5 text-center">
                <p className="text-sm font-semibold text-neutral-900">You&rsquo;ve already set up your account.</p>
                <Link
                  href={`/login?next=/customer/contracts/${lease}/sign`}
                  className="font-heading mt-4 inline-block w-full rounded-md bg-red-600 py-3 text-sm font-bold text-white hover:bg-red-700"
                >
                  Sign in to continue →
                </Link>
              </div>
            ) : (
              <div className="rounded-xl border border-neutral-200 bg-white p-5">
                <div className="mb-4 flex items-center gap-2">
                  <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
                  <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">
                    Step 1: Create your account
                  </h2>
                </div>
                <p className="mb-4 text-sm text-neutral-500">
                  Set a password for {leaseAgreement.customer_email ?? "your account"}. You&rsquo;ll sign your lease
                  agreement next.
                </p>

                <div className="space-y-3">
                  <div>
                    <label className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">Password</label>
                    <input
                      type="password"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      onBlur={() => setTouched(true)}
                      placeholder="Letter + number, 8+ chars"
                      aria-invalid={touched && !!passwordErr}
                      className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-red-600 focus:outline-none"
                    />
                    {touched && passwordErr && <p className="mt-1 text-xs text-red-600">{passwordErr}</p>}
                  </div>
                  <div>
                    <label className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
                      Confirm password
                    </label>
                    <input
                      type="password"
                      value={passwordConfirmation}
                      onChange={(e) => setPasswordConfirmation(e.target.value)}
                      onBlur={() => setTouched(true)}
                      aria-invalid={touched && !!confirmErr}
                      className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-red-600 focus:outline-none"
                    />
                    {touched && confirmErr && <p className="mt-1 text-xs text-red-600">{confirmErr}</p>}
                  </div>
                </div>

                {activateError && <p className="mt-3 text-sm text-red-600">{activateError}</p>}

                <button
                  onClick={handleActivate}
                  disabled={activating}
                  className="font-heading mt-4 w-full rounded-md bg-red-600 py-3 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40"
                >
                  {activating ? "Creating account…" : "Create account →"}
                </button>
              </div>
            )}
          </div>
        )}

        <div className="mt-6 text-center text-sm text-neutral-500">
          <Link href="/faq" className="font-semibold text-neutral-900 underline">
            Have questions about how this works?
          </Link>
          <span className="mx-2">&middot;</span>
          <Link href="/login" className="font-semibold text-neutral-900 underline">
            Back to sign in
          </Link>
        </div>
      </div>
    </main>
  );
}
