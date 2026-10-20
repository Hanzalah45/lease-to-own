"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { ApiError } from "@/lib/api";
import { endLeaseAsStaff, type LeaseEndReason } from "@/lib/payments";

const REASONS: Array<{ value: LeaseEndReason; label: string; effect: string }> = [
  { value: "returned", label: "The equipment was returned", effect: "The equipment goes back to your inventory." },
  { value: "purchased", label: "The customer completed the early purchase", effect: "The customer is marked as the owner of the equipment." },
  { value: "other", label: "Another reason", effect: "The equipment is left as it is." },
];

/**
 * Staff end a lease that was already picked up (client, Joel, 2026-10-16: "is it possible for me to cancel those types of
 * situations or will I always need you?"). One step: AutoPay off, the monthly payments nobody has been charged for removed,
 * the equipment released, a note on the application. It says plainly what it does not do (refund, waive a late fee).
 */
export function EndLeaseModal({
  leaseId,
  customerName,
  scheduledCount,
  onClose,
  onEnded,
}: {
  leaseId: number | string;
  customerName: string;
  /** How many monthly payments are still scheduled and would be removed. */
  scheduledCount: number;
  onClose: () => void;
  onEnded: (notice: string) => void;
}) {
  const [reason, setReason] = useState<LeaseEndReason>("returned");
  const [note, setNote] = useState("");
  const [taxCollected, setTaxCollected] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const chosen = REASONS.find((r) => r.value === reason)!;
  const ready = reason !== "purchased" || taxCollected;

  const close = () => {
    if (!busy) onClose();
  };

  async function handleEnd() {
    if (!ready || busy) return;
    setBusy(true);
    setError(null);
    try {
      const result = await endLeaseAsStaff(leaseId, { reason, note: note.trim(), taxCollected: reason === "purchased" ? taxCollected : undefined });
      onEnded(
        `Lease ended. ${result.removed} scheduled monthly payment${result.removed === 1 ? "" : "s"} removed and AutoPay turned off.${
          result.late_fees_left > 0 ? ` ${result.late_fees_left} unpaid late fee${result.late_fees_left === 1 ? " is" : "s are"} still owed.` : ""
        }`,
      );
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not end the lease. Please try again.");
      setBusy(false);
    }
  }

  return (
    <Modal title="End this lease" onClose={close} maxWidthClassName="max-w-lg">
      <div className="space-y-5">
        <p className="text-sm text-neutral-600">
          This ends <span className="font-semibold text-neutral-900">{customerName}</span>&rsquo;s lease. It cannot be undone from here.
        </p>

        <ul className="list-disc space-y-1 pl-5 text-sm text-neutral-700">
          <li>AutoPay is turned off.</li>
          <li>
            The {scheduledCount} monthly payment{scheduledCount === 1 ? "" : "s"} still scheduled {scheduledCount === 1 ? "is" : "are"} removed.
          </li>
          <li>{chosen.effect}</li>
        </ul>
        <p className="rounded-lg bg-neutral-50 px-3 py-2 text-xs text-neutral-600">
          Money already collected is not refunded here (use Refund on the payment itself), and a late fee already charged stays owed.
          A payment still processing at the bank has to clear first.
        </p>

        <div className="space-y-2">
          {REASONS.map((r) => (
            <label
              key={r.value}
              className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${
                reason === r.value ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"
              }`}
            >
              <input
                type="radio"
                name="end-lease-reason"
                className="mt-1"
                checked={reason === r.value}
                disabled={busy}
                onChange={() => {
                  setReason(r.value);
                  setError(null);
                }}
              />
              <span className="text-sm font-semibold text-neutral-900">{r.label}</span>
            </label>
          ))}
        </div>

        {reason === "purchased" && (
          <label className="flex cursor-pointer items-start gap-3 text-sm text-neutral-700">
            <input type="checkbox" className="mt-1" checked={taxCollected} disabled={busy} onChange={(e) => setTaxCollected(e.target.checked)} />
            <span>The sales tax due on the early purchase has been collected.</span>
          </label>
        )}

        <div>
          <label htmlFor="end-lease-note" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            Note (optional)
          </label>
          <textarea
            id="end-lease-note"
            value={note}
            maxLength={500}
            rows={2}
            disabled={busy}
            onChange={(e) => setNote(e.target.value)}
            className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"
          />
        </div>

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
            onClick={handleEnd}
            disabled={busy || !ready}
            className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
          >
            {busy ? "Ending…" : "End lease"}
          </button>
        </div>
      </div>
    </Modal>
  );
}
