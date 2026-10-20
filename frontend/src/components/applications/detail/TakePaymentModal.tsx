"use client";

import { useEffect, useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import {
  getAutopayMethodsAsStaff,
  takeApplicationPayment,
  takePayment,
  type StaffAutopayMethods,
  type TakeMethod,
} from "@/lib/payments";
import type { Payment } from "@/types/lease-agreement";

/** Cents, so adding and comparing amounts never drifts. */
const cents = (dollars: number) => Math.round(dollars * 100);

/** The card fee on an amount, priced exactly as the server prices it (half-up, in whole cents). */
function cardFee(amount: number, bps: number): number {
  return Math.floor((cents(amount) * bps + 5000) / 10000) / 100;
}

const TYPE_LABEL: Record<string, string> = {
  rental: "monthly payment",
  late_fee: "late fee",
  deposit: "security deposit",
  pickup_balance: "tracking fee and first month",
};

type Target =
  | { kind: "payment"; payment: Payment }
  | { kind: "application"; applicationId: number; leaseId: number; type: "deposit" | "pickup_balance"; due: number; existing?: Payment };

/**
 * Staff take a payment toward something that is due, in whole or in part (client, Joel, 2026-10-19 checklist, sections 1 and 7): cash, a
 * check or another method received in the office, or a charge to the customer's saved bank account or card after they agreed. A payment
 * can be taken in several parts, by different methods, now and later; it is paid when the parts add up (real case: part of the deposit
 * in cash and the rest on a card).
 */
export function TakePaymentModal({
  target,
  onClose,
  onDone,
}: {
  target: Target;
  onClose: () => void;
  /** The payment was taken (or the charge started); a sentence for staff. */
  onDone: (notice: string) => void;
}) {
  const existing = target.kind === "payment" ? target.payment : (target.existing ?? null);
  const leaseId = target.kind === "payment" ? target.payment.lease_agreement_id : target.leaseId;
  const type = target.kind === "payment" ? target.payment.type : target.type;
  const price = target.kind === "payment" ? Number(target.payment.amount) : target.due;
  const paidSoFar = existing ? price - (existing.remaining_amount ?? price) : 0;
  const left = existing ? (existing.chargeable_amount ?? price) : price;

  const [methods, setMethods] = useState<StaffAutopayMethods | null>(null);
  const [methodError, setMethodError] = useState<string | null>(null);
  const [method, setMethod] = useState<TakeMethod>("cash");
  const [everything, setEverything] = useState(true);
  const [amountText, setAmountText] = useState("");
  const [note, setNote] = useState("");
  const [agreed, setAgreed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    getAutopayMethodsAsStaff(leaseId)
      .then((m) => {
        if (!cancelled) setMethods(m);
      })
      .catch((err) => {
        if (!cancelled) setMethodError(err instanceof ApiError ? err.message : "Could not read the saved payment methods.");
      });

    return () => {
      cancelled = true;
    };
  }, [leaseId]);

  const bankUsable = !!methods?.bank && !methods.bank_lock;
  const cardUsable = !!methods?.card && !methods.card_lock;
  const charged = method === "bank" || method === "card";

  const typed = Number(amountText);
  const amount = everything ? left : typed;
  const amountError = everything
    ? null
    : !/^\d+(\.\d{1,2})?$/.test(amountText.trim()) || typed <= 0
      ? "Enter dollars and cents, like 25.50."
      : cents(typed) > cents(left)
        ? `At most ${money(left)} can still be paid toward this.`
        : null;
  const fee = method === "card" ? cardFee(amount, methods?.card_fee_bps ?? 300) : 0;
  const ready = !amountError && amount > 0 && (!charged || agreed);

  const close = () => {
    if (!busy) onClose();
  };

  async function submit() {
    if (!ready || busy) return;
    setBusy(true);
    setError(null);
    try {
      const body = {
        method,
        amount,
        note: note.trim(),
        expectedTotalCents: charged ? cents(amount) + cents(fee) : undefined,
        customerAgreed: charged ? agreed : undefined,
      };
      const updated =
        target.kind === "payment"
          ? await takePayment(target.payment.id, body)
          : await takeApplicationPayment(target.applicationId, target.type, body);

      const stillDue = updated.remaining_amount ?? 0;
      const parts = updated.parts ?? [];
      const last = parts[parts.length - 1];
      onDone(
        updated.status === "paid"
          ? `${money(amount)} taken. The ${TYPE_LABEL[type]} is now paid in full.`
          : last?.status === "pending"
            ? `A bank debit of ${money(amount)} was started. It counts once the bank confirms it. ${money(stillDue)} is still due.`
            : `${money(amount)} taken. ${money(stillDue)} is still due.`,
      );
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "That did not work. Please try again.");
      setBusy(false);
    }
  }

  const options: Array<{ value: TakeMethod; label: string; note: string; disabled: boolean }> = [
    { value: "cash", label: "Cash", note: "Received in the office.", disabled: false },
    { value: "check", label: "Check", note: "Received in the office.", disabled: false },
    { value: "other", label: "Another way", note: "Any other way it was received.", disabled: false },
    {
      value: "bank",
      label: "Saved bank account",
      note: methods?.bank_lock ? "This account is locked." : methods?.bank ? "No card fee. Settles in a few business days." : "No bank account is saved.",
      disabled: !bankUsable,
    },
    {
      value: "card",
      label: "Saved card",
      note: methods?.card_lock ? "This card is locked." : methods?.card ? `Includes the card fee (${money(cardFee(amount > 0 && !amountError ? amount : 0, methods.card_fee_bps ?? 300))} on this amount).` : "No card is saved.",
      disabled: !cardUsable,
    },
  ];

  return (
    <Modal title="Take a payment" onClose={close} maxWidthClassName="max-w-lg">
      <div className="space-y-5">
        <dl className="space-y-1 rounded-lg bg-neutral-50 px-4 py-3 text-sm">
          <div className="flex justify-between gap-4">
            <dt className="text-neutral-500">The {TYPE_LABEL[type]}</dt>
            <dd className="font-semibold tabular-nums text-neutral-900">{money(price)}</dd>
          </div>
          {paidSoFar > 0 && (
            <div className="flex justify-between gap-4">
              <dt className="text-neutral-500">Already paid in parts</dt>
              <dd className="tabular-nums text-neutral-700">{money(paidSoFar)}</dd>
            </div>
          )}
          <div className="flex justify-between gap-4 border-t border-neutral-200 pt-1">
            <dt className="font-semibold text-neutral-700">Still due</dt>
            <dd className="font-semibold tabular-nums text-neutral-900">{money(left)}</dd>
          </div>
        </dl>

        <fieldset className="space-y-2" disabled={busy}>
          <legend className="mb-1 text-xs font-bold uppercase tracking-wide text-neutral-500">How was it paid</legend>
          {options.map((o) => (
            <label
              key={o.value}
              className={`flex items-start gap-3 rounded-lg border p-3 ${
                o.disabled ? "cursor-not-allowed border-neutral-200 opacity-50" : method === o.value ? "cursor-pointer border-red-600 bg-red-50" : "cursor-pointer border-neutral-200 hover:bg-neutral-50"
              }`}
            >
              <input
                type="radio"
                name="take-method"
                className="mt-1"
                checked={method === o.value}
                disabled={o.disabled}
                onChange={() => {
                  setMethod(o.value);
                  setError(null);
                }}
              />
              <span className="text-sm">
                <span className="font-semibold text-neutral-900">{o.label}</span>
                <span className="block text-xs text-neutral-500">{o.note}</span>
              </span>
            </label>
          ))}
          {methodError && <p className="text-xs text-red-600">{methodError}</p>}
        </fieldset>

        <fieldset className="space-y-2" disabled={busy}>
          <legend className="mb-1 text-xs font-bold uppercase tracking-wide text-neutral-500">How much</legend>
          <label className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${everything ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"}`}>
            <input type="radio" name="take-amount" className="mt-1" checked={everything} onChange={() => setEverything(true)} />
            <span className="text-sm font-semibold text-neutral-900">Everything that is still due ({money(left)})</span>
          </label>
          <label className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${!everything ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"}`}>
            <input type="radio" name="take-amount" className="mt-1" checked={!everything} onChange={() => setEverything(false)} />
            <span className="flex-1">
              <span className="block text-sm font-semibold text-neutral-900">Part of it</span>
              {!everything && (
                <span className="mt-2 flex items-center gap-1.5">
                  <span className="text-sm text-neutral-500">$</span>
                  <input
                    type="text"
                    inputMode="decimal"
                    aria-label="Amount taken"
                    value={amountText}
                    maxLength={10}
                    onChange={(e) => setAmountText(e.target.value)}
                    placeholder="0.00"
                    className="w-32 rounded-md border border-neutral-300 px-3 py-1.5 text-sm tabular-nums"
                  />
                </span>
              )}
              {!everything && amountText !== "" && amountError && <span className="mt-1 block text-xs text-red-600">{amountError}</span>}
            </span>
          </label>
        </fieldset>

        {charged ? (
          <div className="space-y-3">
            <p className="rounded-lg bg-neutral-50 px-4 py-3 text-sm text-neutral-700">
              The customer pays <span className="font-semibold text-neutral-900">{money(amount > 0 && !amountError ? amount + fee : 0)}</span>
              {fee > 0 ? `, which includes a ${money(fee)} card fee` : ""}.
            </p>
            <label className="flex cursor-pointer items-start gap-3 text-sm text-neutral-800">
              <input type="checkbox" className="mt-1" checked={agreed} disabled={busy} onChange={(e) => setAgreed(e.target.checked)} />
              <span>The customer agreed to this charge.</span>
            </label>
          </div>
        ) : (
          <div>
            <label htmlFor="take-note" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
              Note (optional)
            </label>
            <input
              id="take-note"
              type="text"
              value={note}
              maxLength={300}
              disabled={busy}
              onChange={(e) => setNote(e.target.value)}
              placeholder="For example: check number 1042"
              className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"
            />
          </div>
        )}

        {amount > 0 && !amountError && cents(amount) < cents(left) && (
          <p className="text-xs text-neutral-500">The rest ({money(left - amount)}) stays due. It can be paid later in one go or in more parts.</p>
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
            onClick={submit}
            disabled={busy || !ready}
            className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
          >
            {busy ? "Working…" : charged ? `Charge ${ready ? money(amount + fee) : ""}`.trim() : `Record ${ready ? money(amount) : ""}`.trim()}
          </button>
        </div>
      </div>
    </Modal>
  );
}
