"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { ApiError } from "@/lib/api";
import type { PaymentMethodsStatus } from "@/lib/payment-methods";

/** A calendar day as the customer reads it (the API sends it as Y-m-d). */
function readableDay(day: string): string {
  const [y, m, d] = day.split("-").map(Number);

  return new Date(y, m - 1, d).toLocaleDateString(undefined, { weekday: "long", month: "long", day: "numeric", year: "numeric" });
}

/**
 * Turning AutoPay off from the portal (client, Joel, 2026-10-19 checklist, section 5). The AutoPay authorization asks for written notice
 * at least three business days before the next scheduled payment, so AutoPay keeps charging until then and is switched off by the system
 * on that day. Until then the customer can keep it on after all.
 */
export function AutopayRevocationCard({
  status,
  onRevoke,
  onKeep,
  onChanged,
}: {
  status: PaymentMethodsStatus;
  onRevoke: () => Promise<PaymentMethodsStatus>;
  onKeep: () => Promise<PaymentMethodsStatus>;
  onChanged: (next: PaymentMethodsStatus) => void;
}) {
  const [asking, setAsking] = useState(false);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const pending = status.autopay_revocation ?? null;
  const days = status.autopay_notice_days ?? 3;

  // Only for a lease that has AutoPay on.
  if (status.autopay_enabled !== true) return null;
  if (!pending && !status.can_revoke_autopay) return null;

  async function run(action: () => Promise<PaymentMethodsStatus>) {
    setBusy(true);
    setError(null);
    try {
      onChanged(await action());
      setAsking(false);
      setConfirmed(false);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "That did not work. Please try again.");
    } finally {
      setBusy(false);
    }
  }

  if (pending) {
    return (
      <div className="rounded-xl border border-amber-300 bg-amber-50 p-5">
        <h3 className="font-heading text-sm font-bold uppercase tracking-wide text-neutral-900">You asked to turn AutoPay off</h3>
        <p className="mt-2 text-sm text-neutral-700">
          AutoPay stays on until <span className="font-semibold">{readableDay(pending.effective_on)}</span>, {days} business days after you asked, so a payment
          that comes due before then is still charged automatically. From that day you pay each monthly payment yourself from your Payments page.
        </p>
        <button
          onClick={() => run(onKeep)}
          disabled={busy}
          className="font-heading mt-3 rounded-md border border-neutral-300 bg-white px-3.5 py-2 text-sm font-bold uppercase tracking-wide text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
        >
          {busy ? "Working…" : "Keep AutoPay on"}
        </button>
        {error && (
          <p role="alert" className="mt-2 text-sm text-red-600">
            {error}
          </p>
        )}
      </div>
    );
  }

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <h3 className="font-heading text-sm font-bold uppercase tracking-wide text-neutral-900">Turn off AutoPay</h3>
      <p className="mt-1 text-sm text-neutral-600">
        You can stop automatic payments any time. Your Lease Agreement asks for notice at least {days} business days before the next scheduled payment.
      </p>
      <button
        onClick={() => setAsking(true)}
        className="font-heading mt-3 rounded-md border border-neutral-300 bg-white px-3.5 py-2 text-sm font-bold uppercase tracking-wide text-neutral-700 hover:bg-neutral-50"
      >
        Turn off AutoPay
      </button>

      {asking && (
        <Modal title="Turn off AutoPay" onClose={() => !busy && setAsking(false)} maxWidthClassName="max-w-md">
          <div className="space-y-4">
            <ul className="list-disc space-y-1.5 pl-5 text-sm text-neutral-700">
              <li>
                AutoPay keeps working for {days} business days after you ask, so a payment that comes due before then is still charged
                automatically.
              </li>
              <li>After that you pay each monthly payment yourself from your Payments page, by its due date.</li>
              <li>A payment that is not paid by its due date can be charged a late fee, as your Lease Agreement says.</li>
              <li>You can change your mind and keep AutoPay on until the day it stops.</li>
            </ul>
            <label className="flex cursor-pointer items-start gap-3 text-sm text-neutral-800">
              <input type="checkbox" className="mt-1" checked={confirmed} disabled={busy} onChange={(e) => setConfirmed(e.target.checked)} />
              <span>I understand, and I want to turn AutoPay off.</span>
            </label>
            {error && (
              <p role="alert" className="text-sm text-red-600">
                {error}
              </p>
            )}
            <div className="flex justify-end gap-2">
              <button
                onClick={() => setAsking(false)}
                disabled={busy}
                className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                onClick={() => run(onRevoke)}
                disabled={busy || !confirmed}
                className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
              >
                {busy ? "Working…" : "Turn off AutoPay"}
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
