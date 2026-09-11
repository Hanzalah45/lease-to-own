"use client";

import Image from "next/image";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useCallback, useEffect, useState } from "react";
import { usePlaidLink } from "react-plaid-link";
import { ApiError } from "@/lib/api";
import {
  exchangeSignedPlaidPublicToken,
  getSignedPlaidLinkToken,
  type PlaidAccount,
  type SignedLinkParams,
} from "@/lib/plaid";
import { CheckCircleIcon, ShieldIcon } from "@/components/icons";

/**
 * Public counterpart to the customer portal's bank-verification step —
 * reached from the signed link an admin's "Request bank verification"
 * action emails (see BankVerificationSigner on the backend). Exists because
 * a guest-originated customer's account has no usable password yet (Phase 6
 * activates it at first-payment/pickup), so they can't log in to reach the
 * authenticated /customer/account page's Plaid button.
 */
export default function VerifyBankPage() {
  return (
    <Suspense fallback={null}>
      <VerifyBankFlow />
    </Suspense>
  );
}

function VerifyBankFlow() {
  const searchParams = useSearchParams();
  const id = searchParams.get("id");
  const hash = searchParams.get("hash");
  const expires = searchParams.get("expires");
  const signature = searchParams.get("signature");
  const hasAllParams = Boolean(id && hash && expires && signature);
  const params: SignedLinkParams | null = hasAllParams ? { id: id!, hash: hash!, expires: expires!, signature: signature! } : null;

  const [linkToken, setLinkToken] = useState<string | null>(null);
  const [connected, setConnected] = useState(false);
  const [verifiedAt, setVerifiedAt] = useState<string | null>(null);
  const [accounts, setAccounts] = useState<PlaidAccount[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(hasAllParams);

  useEffect(() => {
    if (!params) return;
    getSignedPlaidLinkToken(params)
      .then(setLinkToken)
      .catch((err) => setError(err instanceof ApiError ? err.message : "This verification link is invalid or has expired."))
      .finally(() => setLoading(false));
    // Runs once on mount with whatever the URL carried.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const onSuccess = useCallback(
    async (publicToken: string | null) => {
      if (!publicToken || !params) return;
      setError(null);
      try {
        const result = await exchangeSignedPlaidPublicToken(params, publicToken);
        setConnected(true);
        setVerifiedAt(result.verified_at);
        setAccounts(result.accounts);
      } catch (err) {
        setError(err instanceof ApiError ? err.message : "Could not verify your bank connection.");
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  );

  const { open, ready } = usePlaidLink({ token: linkToken, onSuccess });

  return (
    <main
      className="flex flex-1 items-center justify-center p-6"
      style={{
        background:
          "radial-gradient(circle at 15% 20%, rgba(220,38,38,0.12), transparent 45%), radial-gradient(circle at 85% 75%, rgba(220,38,38,0.10), transparent 45%), #fafafa",
      }}
    >
      <div className="w-full max-w-md rounded-2xl bg-white p-8 shadow-xl shadow-black/5">
        <div className="mb-6 flex flex-col items-center text-center">
          <Image src="/logo.png" alt="Prostart Leasing" width={159} height={103} className="mb-3 h-20 w-auto" priority />
          <p className="font-heading text-xs font-semibold uppercase tracking-widest text-neutral-400">Prostart Leasing</p>
          <h1 className="mt-1 text-xl font-bold uppercase tracking-tight text-neutral-900">Connect your bank</h1>
          <p className="mt-1 text-sm text-neutral-500">Verify your bank account to continue processing your lease application.</p>
        </div>

        {!hasAllParams ? (
          <p className="text-center text-sm text-red-600">This verification link is incomplete.</p>
        ) : connected ? (
          <div className="flex items-start gap-3 rounded-md bg-green-50 px-4 py-3">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-600">
              <CheckCircleIcon className="h-4 w-4" />
            </span>
            <div>
              <p className="text-sm font-bold text-green-700">Bank account verified</p>
              <p className="text-xs text-neutral-500">
                {verifiedAt ? `Connected ${new Date(verifiedAt).toLocaleString()}` : "Connected via Plaid"}
              </p>
              {accounts.length > 0 && (
                <ul className="mt-1 space-y-0.5">
                  {accounts.map((a, i) => (
                    <li key={i} className="text-xs text-neutral-500">
                      {a.name} {a.mask ? `•••• ${a.mask}` : ""}
                    </li>
                  ))}
                </ul>
              )}
              <p className="mt-3 text-xs text-neutral-400">You&rsquo;re all set. Prostart Leasing will be in touch with next steps.</p>
            </div>
          </div>
        ) : loading ? (
          <p className="text-center text-sm text-neutral-400">Checking link…</p>
        ) : error && !linkToken ? (
          <p className="text-center text-sm text-red-600">{error}</p>
        ) : (
          <div className="flex items-start gap-3 rounded-md bg-neutral-50 px-4 py-3">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-neutral-400">
              <ShieldIcon className="h-4 w-4" />
            </span>
            <div className="flex-1">
              <p className="text-sm font-bold text-neutral-800">Bank account not verified</p>
              <p className="mb-3 text-xs text-neutral-500">
                Connect your bank via Plaid to verify deposit history and pay frequency for underwriting.
              </p>
              {error && <p className="mb-2 text-xs text-red-600">{error}</p>}
              <button
                onClick={() => open()}
                disabled={!ready}
                className="font-heading rounded-md bg-red-600 px-4 py-2 text-xs font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                Connect bank account →
              </button>
            </div>
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
