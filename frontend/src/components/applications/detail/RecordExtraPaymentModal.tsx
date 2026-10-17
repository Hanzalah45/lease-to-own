"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { recordExtraPaymentAsStaff } from "@/lib/payments";

type Method = "cash" | "check" | "other";

const METHODS: Array<{ value: Method; label: string }> = [
  { value: "cash", label: "Cash" },
  { value: "check", label: "Check" },
  { value: "other", label: "Other" },
];

/**
 * Staff record an extra payment a customer made in the office, toward the early purchase price (client, Joel, 2026-10-16;
 * the signed plan: "a cash payment is recorded manually by an admin"). It is applied like one the customer pays from their
 * phone (contract Section 19, "Additional Funds"), so the early purchase price drops by the same amount.
 */
export function RecordExtraPaymentModal({
  leaseId,
  customerName,
  epoToday,
  onClose,
  onRecorded,
}: {
  leaseId: number | string;
  customerName: string;
  /** The early purchase price today: the most that can be paid toward it. */
  epoToday: number;
  onClose: () => void;
  onRecorded: (notice: string) => void;
}) {
  const [amountText, setAmountText] = useState("");
  const [method, setMethod] = useState<Method>("cash");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const amount = Number(amountText);
  const valid = amountText.trim() !== "" && Number.isFinite(amount) && amount >= 0.01 && amount <= epoToday;
  const after = valid ? Math.max(0, epoToday - amount) : null;

  const close = () => {
    if (!busy) onClose();
  };

  async function handleRecord() {
    if (!valid || busy) return;
    setBusy(true);
    setError(null);
    try {
      const result = await recordExtraPaymentAsStaff(leaseId, { amount: Math.round(amount * 100) / 100, method });
      onRecorded(
        `Extra payment of ${money(amount)} recorded. The early purchase price is now ${money(result.epo_today)}${
          result.epo_today <= 0 ? ". It is paid in full: AutoPay was paused, so finish the purchase (sales tax, release the equipment as owned)." : "."
        }`,
      );
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not record the payment. Please try again.");
      setBusy(false);
    }
  }

  return (
    <Modal title="Record an extra payment" onClose={close} maxWidthClassName="max-w-md">
      <div className="space-y-5">
        <p className="text-sm text-neutral-600">
          Record money <span className="font-semibold text-neutral-900">{customerName}</span> paid toward the early purchase price, for
          example in the office. It is applied right away. The early purchase price is{" "}
          <span className="font-semibold text-neutral-900">{money(epoToday)}</span> today (it excludes tax).
        </p>

        <div>
          <label htmlFor="record-extra-amount" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            Amount received
          </label>
          <div className="relative">
            <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-neutral-400">$</span>
            <input
              id="record-extra-amount"
              type="number"
              inputMode="decimal"
              min={0.01}
              max={epoToday}
              step="0.01"
              value={amountText}
              disabled={busy}
              onChange={(e) => {
                setAmountText(e.target.value);
                setError(null);
              }}
              className="w-full rounded-md border border-neutral-300 py-2 pl-7 pr-3 text-sm"
            />
          </div>
        </div>

        <div>
          <label htmlFor="record-extra-method" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            Received by
          </label>
          <select
            id="record-extra-method"
            value={method}
            onChange={(e) => setMethod(e.target.value as Method)}
            disabled={busy}
            className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"
          >
            {METHODS.map((m) => (
              <option key={m.value} value={m.value}>
                {m.label}
              </option>
            ))}
          </select>
        </div>

        {after !== null && (
          <p className="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-700">
            The early purchase price goes from <span className="font-semibold">{money(epoToday)}</span> to{" "}
            <span className="font-semibold">{money(after)}</span>.
          </p>
        )}

        {error && (
          <p role="alert" className="text-sm text-red-600">
            {error}
          </p>
        )}

        <div className="flex justify-end gap-2">
          <button
            onClick={close}
            disabled={busy}
            className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            onClick={handleRecord}
            disabled={busy || !valid}
            className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
          >
            {busy ? "Saving…" : valid ? `Record ${money(amount)}` : "Record payment"}
          </button>
        </div>
      </div>
    </Modal>
  );
}
