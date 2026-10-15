"use client";

import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { money } from "@/components/applications/wizard/types";
import { formatDateOnly } from "@/lib/dates";
import { firstMonthCharge, firstMonthMirror, methodLabel, outcomeUnknown, paymentTotal } from "@/lib/application-progress";
import type { Payment } from "@/types/lease-agreement";

const TYPE_LABEL: Record<Payment["type"], string> = {
  deposit: "Security deposit",
  pickup_balance: "Tracking fee and first month",
  rental: "Monthly payment",
  late_fee: "Late fee",
};

/** How many upcoming monthly payments to list before folding the rest into one line. */
const UPCOMING_SHOWN = 2;

function badge(p: Payment): { label: string; className: string } {
  switch (p.status) {
    case "paid":
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

/**
 * `coveredBy` is the tracking fee and first month charge this row stands in for (the first monthly payment, recorded when the
 * lease is marked delivered): the row follows that charge, so it never says Paid while the money is still on its way.
 */
function Row({ p, coveredBy }: { p: Payment; coveredBy?: Payment }) {
  const b = badge(coveredBy ?? p);
  const fee = Number(p.card_fee_amount ?? 0);
  const reason = failureReason(p);
  const paid = (coveredBy ?? p).status === "paid";
  const dateText = paid && p.paid_date ? `Paid ${formatDateOnly(p.paid_date)}` : `Due ${formatDateOnly(p.due_date)}`;

  return (
    <li className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 py-3">
      <div className="min-w-0">
        <p className="text-sm font-semibold text-neutral-900">{TYPE_LABEL[p.type]}</p>
        <p className="text-xs text-neutral-500">
          {dateText}
          {p.method ? ` · ${methodLabel(p.method)}` : ""}
        </p>
        {reason && <p className="mt-0.5 text-xs font-medium text-red-600">{reason}</p>}
        {outcomeUnknown(p) && <p className="mt-0.5 text-xs font-medium text-amber-700">{p.failure_message}</p>}
        {coveredBy && (
          <p className="mt-0.5 text-xs text-neutral-400">
            {paid
              ? "The first month was collected in the tracking fee and first month payment above. No extra money was taken."
              : "The first month is part of the tracking fee and first month payment above. No extra money is taken, and this line changes when that payment clears."}
          </p>
        )}
      </div>
      <div className="flex items-center gap-3">
        <div className="text-right">
          <p className="text-sm font-semibold tabular-nums text-neutral-900">{money(paymentTotal(p))}</p>
          {fee > 0 && <p className="text-xs text-neutral-400">includes {money(fee)} card fee</p>}
        </div>
        <span className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${b.className}`}>{b.label}</span>
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
 */
export function PaymentsCard({ payments }: { payments: Payment[] }) {
  const sorted = payments.slice().sort((a, b) => a.id - b.id);
  const mirrorId = firstMonthMirror(payments)?.id;
  const firstMonthPayment = firstMonthCharge(payments);
  const rental = sorted.filter((p) => p.type === "rental");
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

  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-5">
      <SectionHeading title="Payments" />
      {rows.length === 0 ? (
        <p className="mt-3 text-sm text-neutral-500">No payments yet.</p>
      ) : (
        <ul className="mt-2 divide-y divide-neutral-100">
          {rows.map((p) => (
            <Row key={p.id} p={p} coveredBy={p.id === mirrorId ? firstMonthPayment : undefined} />
          ))}
        </ul>
      )}
      {folded > 0 && <p className="mt-2 text-xs text-neutral-400">{folded} more monthly payment{folded === 1 ? "" : "s"} scheduled.</p>}
    </div>
  );
}
