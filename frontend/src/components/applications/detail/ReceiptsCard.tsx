"use client";

import { useEffect, useState } from "react";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { Modal } from "@/components/ui/Modal";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { downloadReceipt, listReceipts, sendReceiptAgain, type Receipt } from "@/lib/receipts";

const when = (value: string | null) => (value ? new Date(value).toLocaleString() : "");

/** Sending a receipt again: to the customer's address or another. */
function SendAgainModal({ receipt, customerEmail, onClose, onDone }: { receipt: Receipt; customerEmail?: string | null; onClose: () => void; onDone: (next: Receipt, mailed: boolean, email: string) => void }) {
  const [email, setEmail] = useState(customerEmail ?? "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());

  async function submit() {
    if (!valid || busy) return;
    setBusy(true);
    setError(null);
    try {
      const sent = await sendReceiptAgain(receipt.id, email.trim() === (customerEmail ?? "") ? undefined : email.trim());
      onDone(sent.receipt, sent.mailed, email.trim());
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "That did not work. Please try again.");
      setBusy(false);
    }
  }

  return (
    <Modal title={`Send receipt ${receipt.receipt_number}`} onClose={() => !busy && onClose()} maxWidthClassName="max-w-md">
      <div className="space-y-4">
        <p className="text-sm text-neutral-600">The stored copy of the receipt is emailed as a PDF, exactly as it was written.</p>
        <div>
          <label htmlFor="receipt-email" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            Send it to
          </label>
          <input
            id="receipt-email"
            type="email"
            value={email}
            maxLength={254}
            disabled={busy}
            onChange={(e) => setEmail(e.target.value)}
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
            onClick={onClose}
            disabled={busy}
            className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            onClick={submit}
            disabled={busy || !valid}
            className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
          >
            {busy ? "Sending…" : "Send the receipt"}
          </button>
        </div>
      </div>
    </Modal>
  );
}

function ReceiptRow({ receipt, onSend, onDownload, busy }: { receipt: Receipt; onSend: () => void; onDownload: () => void; busy: boolean }) {
  const refund = receipt.kind === "refund";

  return (
    <li className="py-3">
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
        <div className="min-w-0">
          <p className="text-sm font-semibold text-neutral-900">
            {receipt.receipt_number} <span className="font-normal text-neutral-500">· {refund ? "Refund of" : ""} {receipt.details.description}</span>
          </p>
          <p className="text-xs text-neutral-500">Issued {when(receipt.issued_at)}</p>
          {receipt.details.balance && !refund && receipt.details.balance.paid_in_full === false && (
            <p className="text-xs text-neutral-500">{money(receipt.details.balance.remaining ?? 0)} of that payment was still due.</p>
          )}
        </div>
        <div className="flex items-center gap-3">
          <span className={`text-sm font-semibold tabular-nums ${refund ? "text-red-600" : "text-neutral-900"}`}>
            {refund ? "−" : ""}
            {money(Number(receipt.amount))}
          </span>
          <button onClick={onDownload} disabled={busy} className="text-xs font-semibold text-neutral-600 hover:text-neutral-900 hover:underline disabled:opacity-50">
            Download
          </button>
          <button onClick={onSend} disabled={busy} className="text-xs font-semibold text-green-700 hover:underline disabled:opacity-50">
            Send again
          </button>
        </div>
      </div>
      {(receipt.sends?.length ?? 0) > 0 ? (
        <ul className="mt-1 space-y-0.5 border-l-2 border-neutral-200 pl-2">
          {receipt.sends!.map((s) => (
            <li key={s.id} className={`text-xs ${s.outcome === "failed" ? "text-red-600" : "text-neutral-600"}`}>
              {s.outcome === "failed" ? "Could not be emailed to" : "Emailed to"} {s.email} on {when(s.sent_at)} · {s.trigger === "auto" ? "sent automatically" : s.sent_by ? s.sent_by.name : "sent by staff"}
              {s.outcome === "failed" && s.error ? `: ${s.error}` : ""}
            </li>
          ))}
        </ul>
      ) : (
        <p className="mt-1 text-xs text-neutral-400">Not emailed.</p>
      )}
    </li>
  );
}

/**
 * Every receipt of a lease, newest first: the number, what it was for, how much, and each time it was emailed with the address and the
 * time (client, Joel, 2026-10-19 checklist, section 8). Staff download the stored PDF copy or send a receipt again. The portal knows the
 * mail service took an email, not that the customer opened it.
 *
 * `refreshKey` asks the list to load again when something outside it changed (a payment landed or a refund was made).
 */
export function ReceiptsCard({ leaseId, customerEmail, refreshKey }: { leaseId: number | string; customerEmail?: string | null; refreshKey?: unknown }) {
  const [receipts, setReceipts] = useState<Receipt[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [sending, setSending] = useState<Receipt | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  useEffect(() => {
    let cancelled = false;
    listReceipts(leaseId)
      .then((rows) => {
        if (!cancelled) setReceipts(rows);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Could not load the receipts.");
      });

    return () => {
      cancelled = true;
    };
  }, [leaseId, refreshKey]);

  async function download(receipt: Receipt) {
    setBusyId(receipt.id);
    setError(null);
    try {
      await downloadReceipt(receipt.id, receipt.receipt_number, true);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not download the receipt.");
    } finally {
      setBusyId(null);
    }
  }

  if (!receipts && !error) return null;
  // Nothing to show for a lease that has not taken or returned any money yet.
  if (receipts && receipts.length === 0) return null;

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <SectionHeading title="Receipts" />
      <p className="mt-1 text-xs text-neutral-500">
        A numbered receipt is written for every payment that comes in and every refund, and emailed to the customer as a PDF. The copy kept
        here is the one that was written; sending it again sends that same copy.
      </p>
      {notice && (
        <p role="status" className="mt-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">
          {notice}
        </p>
      )}
      {error && (
        <p role="alert" className="mt-3 text-sm text-red-600">
          {error}
        </p>
      )}
      <ul className="mt-2 divide-y divide-neutral-100">
        {receipts?.map((r) => (
          <ReceiptRow
            key={r.id}
            receipt={r}
            busy={busyId === r.id}
            onDownload={() => void download(r)}
            onSend={() => {
              setNotice(null);
              setSending(r);
            }}
          />
        ))}
      </ul>

      {sending && (
        <SendAgainModal
          receipt={sending}
          customerEmail={customerEmail}
          onClose={() => setSending(null)}
          onDone={(next, mailed, email) => {
            setReceipts((rows) => rows?.map((r) => (r.id === next.id ? next : r)) ?? rows);
            setSending(null);
            if (mailed) {
              setNotice(`Receipt ${next.receipt_number} was emailed to ${email}.`);
            } else {
              setError(`The mail service refused receipt ${next.receipt_number}. Try again, or download it and send it yourself.`);
            }
          }}
        />
      )}
    </div>
  );
}
