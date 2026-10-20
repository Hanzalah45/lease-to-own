"use client";

import { useState } from "react";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { money } from "@/components/applications/wizard/types";
import { PaymentHistoryModal } from "@/components/applications/detail/PaymentHistoryModal";
import { PaymentLinkModal } from "@/components/applications/detail/PaymentLinkModal";
import { RefundModal } from "@/components/applications/detail/RefundModal";
import { TakePaymentModal } from "@/components/applications/detail/TakePaymentModal";
import { ApiError } from "@/lib/api";
import { formatDateOnly } from "@/lib/dates";
import { firstMonthMirror, methodLabel, outcomeUnknown, paymentTotal } from "@/lib/application-progress";
import { cancelUnansweredPart, cancelUnansweredRefund, refundReasonLabel } from "@/lib/payments";
import type { Payment, PaymentDispute, PaymentPart, PaymentRefund } from "@/types/lease-agreement";

const TYPE_LABEL: Record<Payment["type"], string> = {
  deposit: "Security deposit",
  pickup_balance: "Tracking fee and first month",
  rental: "Monthly payment",
  late_fee: "Late fee",
  extra_payment: "Extra payment toward the early purchase price",
};

/** What a paid payment was applied to (client, Joel, 2026-10-19 checklist, section 10). Rent and the one-off charges say it in their name. */
const APPLIED_TO_LABEL: Record<NonNullable<Payment["applied_to"]>, string | null> = {
  rent: null,
  fees: "Applied to fees",
  epo: "Applied to the early purchase price (Additional Funds)",
  deposit: null,
  tracking_fee_and_first_month: null,
  other: null,
};

/** How many upcoming monthly payments to list before folding the rest into one line. */
const UPCOMING_SHOWN = 2;

function badge(p: Payment): { label: string; className: string } {
  switch (p.status) {
    case "paid":
      // Part of it has gone back: still a payment that was made, but not for the full amount any more.
      if ((p.refunded_total ?? 0) > 0) return { label: "Partly refunded", className: "bg-amber-100 text-amber-700" };
      return { label: "Paid", className: "bg-green-100 text-green-700" };
    case "failed":
      return { label: "Failed", className: "bg-red-100 text-red-700" };
    case "refunded":
      return { label: "Refunded", className: "bg-neutral-200 text-neutral-700" };
    default:
      // Pending with a charge already started at the bank or card company is "processing";
      // a scheduled payment nobody has tried to collect yet is just scheduled.
      if (outcomeUnknown(p)) return { label: "No answer", className: "bg-amber-100 text-amber-700" };
      return p.stripe_payment_intent_id
        ? { label: "Processing", className: "bg-amber-100 text-amber-700" }
        : { label: "Scheduled", className: "bg-neutral-100 text-neutral-600" };
  }
}

/** Why a charge failed, in the processor's words: stored on the payment, or on its latest failed automatic attempt. */
function failureReason(p: Payment): string | null {
  if (p.status !== "failed") return null;
  if (p.failure_message) return p.failure_message;
  const lastFailed = p.attempts
    ?.slice()
    .sort((a, b) => a.id - b.id)
    .reverse()
    .find((a) => a.status === "failed" && a.failure_message);
  return lastFailed?.failure_message ?? null;
}

/** One refund of a payment in words: what went back, when, why, and what is still to happen. */
function RefundLine({ refund, canAct, busy, onCancel }: { refund: PaymentRefund; canAct: boolean; busy: boolean; onCancel: () => void }) {
  const amount = money(Number(refund.amount));
  const why = refundReasonLabel(refund.reason);

  if (refund.status === "succeeded") {
    return (
      <p className="mt-0.5 text-xs text-neutral-600">
        Refunded {amount}
        {refund.refunded_at ? ` on ${new Date(refund.refunded_at).toLocaleDateString()}` : ""} · {why}
        {refund.refunded_by ? ` · by ${refund.refunded_by.name}` : ""}
      </p>
    );
  }

  if (refund.status === "failed") {
    return (
      <p className="mt-0.5 text-xs text-red-600">
        A refund of {amount} did not go through{refund.failure_message ? `: ${refund.failure_message}` : "."}
      </p>
    );
  }

  // Pending. With a Stripe number it is a bank refund on its way; without one Stripe never answered, so nobody knows if it was made.
  if (refund.stripe_refund_id) {
    return <p className="mt-0.5 text-xs font-medium text-amber-700">A refund of {amount} is on its way. It shows as done here once the bank confirms it.</p>;
  }

  return (
    <p className="mt-0.5 text-xs font-medium text-amber-700">
      A refund of {amount} got no answer from Stripe, so it is not known if it was made. Check Stripe first.{" "}
      {canAct && (
        <button onClick={onCancel} disabled={busy} className="font-semibold underline disabled:opacity-50">
          {busy ? "Cancelling…" : "It was not made, cancel it"}
        </button>
      )}
    </p>
  );
}

const PART_METHOD: Record<PaymentPart["method"], string> = { ach: "bank account", card: "card", cash: "cash", check: "check", other: "another way" };

/** One piece of a payment that was paid in parts: how much, how, when, by whom, and what became of it. */
function PartLine({ part, canAct, busy, onCancel }: { part: PaymentPart; canAct: boolean; busy: boolean; onCancel: () => void }) {
  const total = Number(part.amount) + Number(part.card_fee_amount ?? 0);
  const how = PART_METHOD[part.method];

  if (part.status === "failed") {
    return (
      <li className="text-xs text-red-600">
        {money(total)} by {how} did not go through{part.failure_message ? `: ${part.failure_message}` : "."}
      </li>
    );
  }
  if (part.status === "pending") {
    // A charge with no answer from Stripe (no Stripe number is ever sent to the screen: that is what "no answer yet" means here).
    const unanswered = !!part.failure_message && part.failure_message.startsWith("No answer");

    return (
      <li className="text-xs font-medium text-amber-700">
        {money(total)} by {how} is {unanswered ? "waiting for an answer from Stripe." : "on its way. It counts once the bank confirms it."}{" "}
        {unanswered && canAct && (
          <button onClick={onCancel} disabled={busy} className="font-semibold underline disabled:opacity-50">
            {busy ? "Cancelling…" : "It was not made, cancel it"}
          </button>
        )}
      </li>
    );
  }

  return (
    <li className="text-xs text-neutral-600">
      {money(total)} by {how}
      {part.paid_date ? ` on ${formatDateOnly(part.paid_date)}` : ""}
      {Number(part.card_fee_amount) > 0 ? ` (includes ${money(Number(part.card_fee_amount))} card fee)` : ""}
      {part.recorded_by ? ` · ${part.recorded_by.name}` : part.started_by === "customer" ? " · the customer" : ""}
      {part.status === "refunded" ? " · taken back" : (part.refunded_total ?? 0) > 0 ? ` · ${money(part.refunded_total ?? 0)} refunded` : ""}
    </li>
  );
}

function DisputeLine({ dispute }: { dispute: PaymentDispute }) {
  const amount = money(Number(dispute.amount));
  if (!dispute.closed_at) {
    return (
      <p className="mt-0.5 text-xs font-medium text-red-600">
        Chargeback of {amount}: the customer disputed this payment with their bank.
        {dispute.evidence_due_by ? ` Send your evidence in Stripe by ${new Date(dispute.evidence_due_by).toLocaleDateString()}.` : " Respond in Stripe."}
      </p>
    );
  }

  return (
    <p className="mt-0.5 text-xs text-neutral-600">
      Chargeback of {amount} {dispute.status === "lost" ? "was lost, the money went back to the customer" : dispute.status === "won" ? "was won, nothing was taken back" : "was closed"}.
    </p>
  );
}

/** `breakdown` says what a charge is made of, when that is not obvious from its name. */
function Row({
  p,
  breakdown,
  canManage,
  cancellingId,
  onRefund,
  onHistory,
  onTake,
  onLink,
  cancellingPartId,
  onCancelPart,
  onCancelRefund,
}: {
  p: Payment;
  breakdown?: string;
  canManage: boolean;
  cancellingId: number | null;
  onRefund: () => void;
  onHistory: () => void;
  onTake: () => void;
  onLink: () => void;
  cancellingPartId: number | null;
  onCancelPart: (partId: number) => void;
  onCancelRefund: (refundId: number) => void;
}) {
  const b = badge(p);
  const fee = Number(p.card_fee_amount ?? 0);
  const reason = failureReason(p);
  const dateText = (p.status === "paid" || p.status === "refunded") && p.paid_date ? `Paid ${formatDateOnly(p.paid_date)}` : `Due ${formatDateOnly(p.due_date)}`;
  const parts = p.parts ?? [];
  const hasPaidParts = parts.some((x) => x.status === "paid");
  // A payment paid in parts can have one part taken back even while the rest is still due.
  const mayRefund =
    canManage &&
    ((p.status === "paid" && (p.refundable_amount ?? 0) > 0) ||
      (hasPaidParts && p.status !== "paid" && parts.some((x) => x.status === "paid" && (x.refundable_amount ?? 0) > 0)));
  // Paid in parts, or paid in one go: staff can take a payment toward anything that is still due.
  const mayTake =
    canManage &&
    ["pending", "failed"].includes(p.status) &&
    ["rental", "late_fee", "deposit", "pickup_balance"].includes(p.type) &&
    !(p.stripe_payment_intent_id && p.status === "pending");

  return (
    <li className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 py-3">
      <div className="min-w-0">
        <p className="text-sm font-semibold text-neutral-900">{TYPE_LABEL[p.type]}</p>
        <p className="text-xs text-neutral-500">
          {dateText}
          {p.method ? ` · ${methodLabel(p.method)}` : ""}
        </p>
        {breakdown && <p className="mt-0.5 text-xs text-neutral-400">{breakdown}</p>}
        {canManage && p.status === "paid" && APPLIED_TO_LABEL[p.applied_to ?? "other"] && (
          <p className="mt-0.5 text-xs text-neutral-400">{APPLIED_TO_LABEL[p.applied_to ?? "other"]}</p>
        )}
        {reason && <p className="mt-0.5 text-xs font-medium text-red-600">{reason}</p>}
        {outcomeUnknown(p) && <p className="mt-0.5 text-xs font-medium text-amber-700">{p.failure_message}</p>}
        {p.refunds?.map((r) => (
          <RefundLine key={r.id} refund={r} canAct={canManage} busy={cancellingId === r.id} onCancel={() => onCancelRefund(r.id)} />
        ))}
        {p.disputes?.map((d) => <DisputeLine key={d.id} dispute={d} />)}
        {parts.length > 0 && (
          <ul className="mt-1 space-y-0.5 border-l-2 border-neutral-200 pl-2">
            {parts.map((part) => (
              <PartLine key={part.id} part={part} canAct={canManage} busy={cancellingPartId === part.id} onCancel={() => onCancelPart(part.id)} />
            ))}
            {p.status !== "paid" && p.status !== "refunded" && hasPaidParts && (
              <li className="text-xs font-semibold text-neutral-800">{money(p.remaining_amount ?? 0)} is still due.</li>
            )}
          </ul>
        )}
        {canManage && (p.notes?.length ?? 0) > 0 && (
          <p className="mt-0.5 text-xs text-neutral-500">
            <span className="font-semibold text-neutral-600">Note:</span> {p.notes![p.notes!.length - 1].body.slice(0, 120)}
            {p.notes![p.notes!.length - 1].body.length > 120 ? "…" : ""}
          </p>
        )}
      </div>
      <div className="flex items-center gap-3">
        <div className="text-right">
          <p className="text-sm font-semibold tabular-nums text-neutral-900">{money(paymentTotal(p))}</p>
          {fee > 0 && <p className="text-xs text-neutral-400">includes {money(fee)} card fee</p>}
        </div>
        <span className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${b.className}`}>{b.label}</span>
        {mayTake && (
          <button onClick={onTake} className="text-xs font-semibold text-green-700 hover:underline">
            {hasPaidParts ? "Take the rest" : "Take a payment"}
          </button>
        )}
        {mayTake && (
          <button onClick={onLink} className="text-xs font-semibold text-blue-700 hover:underline">
            Send a link
          </button>
        )}
        {mayRefund && (
          <button onClick={onRefund} className="text-xs font-semibold text-red-600 hover:underline">
            Refund
          </button>
        )}
        {canManage && p.status !== "pending" && (
          <button onClick={onHistory} className="text-xs font-semibold text-neutral-500 hover:text-neutral-800 hover:underline">
            History{(p.notes?.length ?? 0) > 0 ? ` (${p.notes!.length})` : ""}
          </button>
        )}
      </div>
    </li>
  );
}

/**
 * Every payment on this lease with its real status (paid, failed, processing,
 * scheduled), what was charged including the card fee, and why a charge failed
 * (client, Joel, 2026-10-13: he could not see on his side whether a customer's
 * payment went through). The monthly schedule is long, so it shows what has
 * happened or needs a look, the next few due, and a count of the rest.
 *
 * The first monthly payment is left out once the tracking fee and first month was
 * charged: marking the lease delivered records it without any money moving, because
 * its money is in that charge, and listing both made it look like the first month
 * was taken twice (Joel, 2026-10-16: "very misleading"). The charge's own line says
 * what it is made of instead.
 *
 * Staff with payment tracking can refund a paid payment from here (`canManage`), all of it or part, and see every refund and
 * chargeback of a payment under it. `onChanged` asks the page to load the payments again after a refund.
 */
export function PaymentsCard({
  payments,
  canManage = false,
  customerEmail,
  onChanged,
}: {
  payments: Payment[];
  canManage?: boolean;
  /** Where a payment link goes by default. */
  customerEmail?: string | null;
  onChanged?: () => void;
}) {
  const [refunding, setRefunding] = useState<Payment | null>(null);
  const [viewing, setViewing] = useState<Payment | null>(null);
  const [taking, setTaking] = useState<Payment | null>(null);
  const [linking, setLinking] = useState<Payment | null>(null);
  const [cancellingPartId, setCancellingPartId] = useState<number | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [cancellingId, setCancellingId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const sorted = payments.slice().sort((a, b) => a.id - b.id);
  const mirror = firstMonthMirror(payments);
  const rental = sorted.filter((p) => p.type === "rental" && p.id !== mirror?.id);
  const others = sorted.filter((p) => p.type !== "rental");

  const happened = rental.filter((p) => p.status !== "pending" || p.stripe_payment_intent_id);
  const scheduled = rental
    .filter((p) => p.status === "pending" && !p.stripe_payment_intent_id)
    .sort((a, b) => a.due_date.localeCompare(b.due_date));
  const upcoming = scheduled.slice(0, UPCOMING_SHOWN);
  const folded = scheduled.length - upcoming.length;

  const rows = [...others, ...happened, ...upcoming].sort((a, b) => {
    // Charges that actually happened first (oldest to newest), then what is coming up.
    const aUp = a.status === "pending" && !a.stripe_payment_intent_id;
    const bUp = b.status === "pending" && !b.stripe_payment_intent_id;
    if (aUp !== bUp) return aUp ? 1 : -1;
    return aUp ? a.due_date.localeCompare(b.due_date) : a.id - b.id;
  });

  // What the tracking fee and first month charge is made of: the tracking fee plus the first monthly payment.
  const breakdownFor = (p: Payment): string | undefined => {
    if (p.type !== "pickup_balance" || !mirror) return undefined;
    const firstMonth = Number(mirror.amount);

    return `Tracking fee ${money(Number(p.amount) - firstMonth)} plus the first month ${money(firstMonth)}`;
  };

  async function cancelPart(partId: number) {
    setError(null);
    setCancellingPartId(partId);
    try {
      await cancelUnansweredPart(partId);
      setNotice("That charge was cancelled.");
      onChanged?.();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not cancel that charge. Please try again.");
    } finally {
      setCancellingPartId(null);
    }
  }

  async function cancelRefund(refundId: number) {
    setError(null);
    setCancellingId(refundId);
    try {
      await cancelUnansweredRefund(refundId);
      setNotice("That refund was cancelled.");
      onChanged?.();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not cancel that refund. Please try again.");
    } finally {
      setCancellingId(null);
    }
  }

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <SectionHeading title="Payments" />
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
      {rows.length === 0 ? (
        <p className="mt-3 text-sm text-neutral-500">No payments yet.</p>
      ) : (
        <ul className="mt-2 divide-y divide-neutral-100">
          {rows.map((p) => (
            <Row
              key={p.id}
              p={p}
              breakdown={breakdownFor(p)}
              canManage={canManage}
              cancellingId={cancellingId}
              onRefund={() => {
                setNotice(null);
                setRefunding(p);
              }}
              onHistory={() => setViewing(p)}
              onTake={() => {
                setNotice(null);
                setTaking(p);
              }}
              onLink={() => {
                setNotice(null);
                setLinking(p);
              }}
              cancellingPartId={cancellingPartId}
              onCancelPart={cancelPart}
              onCancelRefund={cancelRefund}
            />
          ))}
        </ul>
      )}
      {folded > 0 && <p className="mt-2 text-xs text-neutral-400">{folded} more monthly payment{folded === 1 ? "" : "s"} scheduled.</p>}

      {taking && (
        <TakePaymentModal
          target={{ kind: "payment", payment: taking }}
          onClose={() => setTaking(null)}
          onDone={(message) => {
            setTaking(null);
            setNotice(message);
            onChanged?.();
          }}
        />
      )}

      {linking && (
        <PaymentLinkModal
          target={{ kind: "payment", payment: linking }}
          customerEmail={customerEmail}
          onClose={() => setLinking(null)}
          onDone={(message) => {
            setLinking(null);
            setNotice(message);
            onChanged?.();
          }}
        />
      )}

      {viewing && <PaymentHistoryModal payment={viewing} onClose={() => setViewing(null)} onChanged={onChanged} />}

      {refunding && (
        <RefundModal
          payment={refunding}
          onClose={() => setRefunding(null)}
          onRefunded={(message) => {
            setRefunding(null);
            setNotice(message);
            onChanged?.();
          }}
        />
      )}
    </div>
  );
}
