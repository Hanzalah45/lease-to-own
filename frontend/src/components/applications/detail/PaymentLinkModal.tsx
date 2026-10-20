"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { createExtraPaymentLink, createPaymentLink, type PaymentLinkMade } from "@/lib/payment-links";
import type { Payment } from "@/types/lease-agreement";

const cents = (dollars: number) => Math.round(dollars * 100);

const TYPE_LABEL: Record<string, string> = {
  rental: "monthly payment",
  late_fee: "late fee",
  deposit: "security deposit",
  pickup_balance: "tracking fee and first month",
};

export type LinkTarget = { kind: "payment"; payment: Payment } | { kind: "extra"; leaseId: number | string };

/** The address of a link, once: copy it, or it is gone (a lost one is replaced by sending the link again). */
export function LinkAddress({ url }: { url: string }) {
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
    } catch {
      setCopied(false);
    }
  }

  return (
    <div className="space-y-1.5">
      <div className="flex gap-2">
        <input
          type="text"
          readOnly
          value={url}
          aria-label="Payment link"
          onFocus={(e) => e.currentTarget.select()}
          className="min-w-0 flex-1 rounded-md border border-neutral-300 bg-neutral-50 px-3 py-2 text-xs text-neutral-700"
        />
        <button
          onClick={copy}
          className="font-heading shrink-0 rounded-md border border-neutral-300 px-3 py-2 text-xs font-bold text-neutral-700 hover:bg-neutral-50"
        >
          {copied ? "Copied" : "Copy"}
        </button>
      </div>
      <p className="text-xs text-neutral-500">This address is shown only now. If it is lost, send the link again to get a new one.</p>
    </div>
  );
}

/**
 * Staff make a payment link and either email it or copy it to send themselves (client, Joel, 2026-10-19 checklist, section 6). The link
 * is for a payment that is due, in whole or in part, or for an extra payment toward the early purchase price.
 */
export function PaymentLinkModal({
  target,
  customerEmail,
  onClose,
  onDone,
}: {
  target: LinkTarget;
  customerEmail?: string | null;
  onClose: () => void;
  /** A link was made (and perhaps sent): a sentence for staff. */
  onDone: (notice: string) => void;
}) {
  const payment = target.kind === "payment" ? target.payment : null;
  const price = payment ? Number(payment.amount) : 0;
  const left = payment ? (payment.chargeable_amount ?? price) : 0;

  const [everything, setEverything] = useState(target.kind === "payment");
  const [amountText, setAmountText] = useState("");
  const [send, setSend] = useState(true);
  const [email, setEmail] = useState(customerEmail ?? "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [made, setMade] = useState<PaymentLinkMade | null>(null);

  const typed = Number(amountText);
  const amount = everything && payment ? left : typed;
  const amountError =
    everything && payment
      ? null
      : !/^\d+(\.\d{1,2})?$/.test(amountText.trim()) || typed <= 0
        ? "Enter dollars and cents, like 25.50."
        : payment && cents(typed) > cents(left)
          ? `At most ${money(left)} is still due.`
          : null;
  const emailError = send && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim()) ? "Enter the email address to send it to." : null;
  const ready = !amountError && amount > 0 && !emailError;

  const close = () => {
    if (!busy) onClose();
  };

  async function submit() {
    if (!ready || busy) return;
    setBusy(true);
    setError(null);
    try {
      const body = {
        amount: everything && payment ? undefined : amount,
        send,
        email: send && email.trim() !== (customerEmail ?? "") ? email.trim() : undefined,
      };
      const result = payment ? await createPaymentLink(payment.id, body) : await createExtraPaymentLink((target as { leaseId: number | string }).leaseId, { ...body, amount });
      setMade(result);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "That did not work. Please try again.");
    } finally {
      setBusy(false);
    }
  }

  if (made) {
    const mailFailed = send && made.mailed === false;

    return (
      <Modal title="Payment link" onClose={() => onDone(mailFailed ? "The link was made, but the email could not be sent." : send ? `The link was emailed to ${made.data.sent_to}.` : "The link was made.")} maxWidthClassName="max-w-lg">
        <div className="space-y-4">
          {send && !mailFailed && <p className="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">Emailed to {made.data.sent_to}.</p>}
          {mailFailed && (
            <p role="alert" className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
              The email could not be sent. Copy the link below and send it yourself, or send it again from the list.
            </p>
          )}
          <p className="text-sm text-neutral-700">
            {made.data.description} for <span className="font-semibold">{money(Number(made.data.amount))}</span>. It works for 14 days.
          </p>
          <LinkAddress url={made.url} />
          <div className="flex justify-end">
            <button
              onClick={() => onDone(mailFailed ? "The link was made, but the email could not be sent." : send ? `The link was emailed to ${made.data.sent_to}.` : "The link was made.")}
              className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700"
            >
              Done
            </button>
          </div>
        </div>
      </Modal>
    );
  }

  return (
    <Modal title={payment ? "Send a payment link" : "Send an extra payment link"} onClose={close} maxWidthClassName="max-w-lg">
      <div className="space-y-5">
        {payment ? (
          <dl className="space-y-1 rounded-lg bg-neutral-50 px-4 py-3 text-sm">
            <div className="flex justify-between gap-4">
              <dt className="text-neutral-500">The {TYPE_LABEL[payment.type] ?? "payment"}</dt>
              <dd className="font-semibold tabular-nums text-neutral-900">{money(price)}</dd>
            </div>
            <div className="flex justify-between gap-4 border-t border-neutral-200 pt-1">
              <dt className="font-semibold text-neutral-700">Still due</dt>
              <dd className="font-semibold tabular-nums text-neutral-900">{money(left)}</dd>
            </div>
          </dl>
        ) : (
          <p className="rounded-lg bg-neutral-50 px-4 py-3 text-sm text-neutral-700">
            A link for an extra payment toward the early purchase price. The customer chooses to pay by bank account or by card.
          </p>
        )}

        <fieldset className="space-y-2" disabled={busy}>
          <legend className="mb-1 text-xs font-bold uppercase tracking-wide text-neutral-500">How much</legend>
          {payment && (
            <label className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${everything ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"}`}>
              <input type="radio" name="link-amount" className="mt-1" checked={everything} onChange={() => setEverything(true)} />
              <span className="text-sm font-semibold text-neutral-900">Everything that is still due ({money(left)})</span>
            </label>
          )}
          <label className={`flex items-start gap-3 rounded-lg border p-3 ${!everything || !payment ? "border-red-600 bg-red-50" : "cursor-pointer border-neutral-200 hover:bg-neutral-50"}`}>
            {payment && <input type="radio" name="link-amount" className="mt-1" checked={!everything} onChange={() => setEverything(false)} />}
            <span className="flex-1">
              <span className="block text-sm font-semibold text-neutral-900">{payment ? "Part of it" : "Amount"}</span>
              {(!everything || !payment) && (
                <span className="mt-2 flex items-center gap-1.5">
                  <span className="text-sm text-neutral-500">$</span>
                  <input
                    type="text"
                    inputMode="decimal"
                    aria-label="Amount of the link"
                    value={amountText}
                    maxLength={10}
                    onChange={(e) => setAmountText(e.target.value)}
                    placeholder="0.00"
                    className="w-32 rounded-md border border-neutral-300 px-3 py-1.5 text-sm tabular-nums"
                  />
                  <span className="text-xs text-neutral-500">before any card fee</span>
                </span>
              )}
              {(!everything || !payment) && amountText !== "" && amountError && <span className="mt-1 block text-xs text-red-600">{amountError}</span>}
            </span>
          </label>
        </fieldset>

        <fieldset className="space-y-2" disabled={busy}>
          <legend className="mb-1 text-xs font-bold uppercase tracking-wide text-neutral-500">Sending it</legend>
          <label className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${send ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"}`}>
            <input type="radio" name="link-send" className="mt-1" checked={send} onChange={() => setSend(true)} />
            <span className="flex-1">
              <span className="block text-sm font-semibold text-neutral-900">Email it now</span>
              {send && (
                <span className="mt-2 block">
                  <input
                    type="email"
                    aria-label="Email address to send the link to"
                    value={email}
                    maxLength={254}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="name@example.com"
                    className="w-full rounded-md border border-neutral-300 px-3 py-1.5 text-sm"
                  />
                  {email !== "" && emailError && <span className="mt-1 block text-xs text-red-600">{emailError}</span>}
                </span>
              )}
            </span>
          </label>
          <label className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${!send ? "border-red-600 bg-red-50" : "border-neutral-200 hover:bg-neutral-50"}`}>
            <input type="radio" name="link-send" className="mt-1" checked={!send} onChange={() => setSend(false)} />
            <span className="text-sm">
              <span className="block font-semibold text-neutral-900">Only make the link</span>
              <span className="block text-xs text-neutral-500">Copy it and send it yourself, for example by text.</span>
            </span>
          </label>
        </fieldset>

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
            {busy ? "Working…" : send ? "Make and email the link" : "Make the link"}
          </button>
        </div>
      </div>
    </Modal>
  );
}
