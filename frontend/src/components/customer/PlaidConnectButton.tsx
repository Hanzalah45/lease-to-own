"use client";

import { useCallback, useEffect, useState } from "react";
import { usePlaidLink } from "react-plaid-link";
import { ApiError } from "@/lib/api";
import { exchangePlaidPublicToken, getPlaidLinkToken, getPlaidStatus, type PlaidAccount } from "@/lib/plaid";
import { CheckCircleIcon, ShieldIcon } from "@/components/icons";

export function PlaidConnectButton() {
  const [linkToken, setLinkToken] = useState<string | null>(null);
  const [connected, setConnected] = useState(false);
  const [verifiedAt, setVerifiedAt] = useState<string | null>(null);
  const [accounts, setAccounts] = useState<PlaidAccount[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    (async () => {
      try {
        const status = await getPlaidStatus();
        setConnected(status.connected);
        setVerifiedAt(status.verified_at);
        if (!status.connected) {
          setLinkToken(await getPlaidLinkToken());
        }
      } catch (err) {
        setError(err instanceof ApiError ? err.message : "Could not load bank verification status.");
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  const onSuccess = useCallback(async (publicToken: string | null) => {
    if (!publicToken) return;
    setError(null);
    try {
      const result = await exchangePlaidPublicToken(publicToken);
      setConnected(true);
      setVerifiedAt(result.verified_at);
      setAccounts(result.accounts);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not verify your bank connection.");
    }
  }, []);

  const { open, ready } = usePlaidLink({
    token: linkToken,
    onSuccess,
  });

  if (loading) {
    return <p className="text-sm text-neutral-400">Checking bank verification status…</p>;
  }

  if (connected) {
    return (
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
        </div>
      </div>
    );
  }

  return (
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
  );
}
