import { money } from "@/components/applications/wizard/types";
import { formatDateOnly } from "@/lib/dates";
import type { Application } from "@/types/application";
import type { Payment } from "@/types/lease-agreement";

/**
 * Where a customer is in the process, worked out from what the system really
 * knows (client, Joel, 2026-10-13): the application page used to show fixed
 * text such as "Contract ready to send for signature" that never changed once
 * the customer signed, and AutoPay setup was not visible to staff at all.
 *
 * "done": finished. "waiting": nothing wrong, the customer or staff still has
 * to act. "attention": something failed or needs a person to look. "manual":
 * marked received by hand with no payment on record. "off": does not apply.
 */
export type StepState = "done" | "waiting" | "attention" | "manual" | "off";

export interface ProgressStep {
  key: "contract" | "autopay" | "deposit" | "pickup_balance" | "delivered";
  label: string;
  state: StepState;
  detail: string;
}

export interface ApplicationProgress {
  steps: ProgressStep[];
  nextStep: string;
}

/** What the customer was actually charged: the bank price plus any card fee. */
export function paymentTotal(p: Payment): number {
  return Number(p.amount) + Number(p.card_fee_amount ?? 0);
}

/**
 * Money actually collected so far, including card fees. The first month is on
 * file twice when the customer paid the tracking fee and first month together:
 * once in that charge and again as the first scheduled monthly payment that
 * "Mark Delivered & Paid" records without any money moving. It is counted once.
 */
export function collectedToDate(payments: Payment[]): number {
  const mirror = firstMonthMirror(payments);

  return payments
    .filter((p) => p.status === "paid" && p.id !== mirror?.id)
    .reduce((sum, p) => sum + paymentTotal(p), 0);
}

/**
 * The tracking fee and first month charge that the first monthly payment stands in for: a paid one, otherwise the latest
 * one that was really charged at the bank or card company (still processing, or declined). A scheduled one nobody has
 * tried to charge does not count.
 */
export function firstMonthCharge(payments: Payment[]): Payment | undefined {
  const charged = payments
    .filter((p) => p.type === "pickup_balance" && (p.status === "paid" || !!p.stripe_payment_intent_id))
    .sort((a, b) => a.id - b.id);

  return charged.find((p) => p.status === "paid") ?? charged[charged.length - 1];
}

/**
 * The first scheduled monthly payment when the first month was collected inside the tracking fee and first month charge.
 * Marking the lease delivered records it as paid on the pickup day without any money moving (client, Joel, 2026-10-16:
 * "its saying the 497 has been paid" while that charge was still processing), so it follows that charge: see
 * firstMonthCharge().
 */
export function firstMonthMirror(payments: Payment[]): Payment | undefined {
  if (!firstMonthCharge(payments)) return undefined;

  return payments
    .filter((p) => p.status === "paid" && p.type === "rental" && !p.stripe_payment_intent_id)
    .sort((a, b) => a.due_date.localeCompare(b.due_date) || a.id - b.id)[0];
}

/**
 * The first-month record of every lease in a list that may hold payments of several leases, with the tracking fee and first
 * month charge it stands in for. That row is bookkeeping, not a payment of its own, so lists of payments leave it out
 * (client, Joel, 2026-10-16: "it shouldnt say tracking and first month, but then also say monthly payment ... its very
 * misleading").
 */
export function firstMonthMirrors(payments: Payment[]): Array<{ mirror: Payment; charge: Payment }> {
  const byLease = new Map<number, Payment[]>();
  for (const p of payments) byLease.set(p.lease_agreement_id, [...(byLease.get(p.lease_agreement_id) ?? []), p]);

  const found: Array<{ mirror: Payment; charge: Payment }> = [];
  for (const group of byLease.values()) {
    const mirror = firstMonthMirror(group);
    const charge = firstMonthCharge(group);
    if (mirror && charge) found.push({ mirror, charge });
  }

  return found;
}

export function methodLabel(method: Payment["method"]): string {
  switch (method) {
    case "ach":
      return "bank account";
    case "card":
      return "card";
    case "cash":
      return "cash";
    case "other":
      return "other method";
    default:
      return "an unknown method";
  }
}

/**
 * A charge whose call to the payment processor got no answer: pending with no
 * charge behind it, and the server's note saying so. Pressing charge again
 * repeats the same charge (never a second one), so it stays available.
 */
export function outcomeUnknown(p: Payment): boolean {
  return (p.type === "deposit" || p.type === "pickup_balance") && p.status === "pending" && !p.stripe_payment_intent_id && !!p.failure_message;
}

/** The most recent payment of a kind (several rows exist when an attempt failed and was tried again). */
function latestOfType(payments: Payment[], type: Payment["type"]): Payment | undefined {
  return payments
    .filter((p) => p.type === type)
    .sort((a, b) => a.id - b.id)
    .pop();
}

function paymentStep(
  key: "deposit" | "pickup_balance",
  label: string,
  payments: Payment[],
  receivedByHand: boolean,
  due: { bank: number; card: number } | undefined,
  pickedUp: boolean,
): ProgressStep {
  const p = latestOfType(payments, key);

  if (p?.status === "paid") {
    const when = p.paid_date ? ` on ${formatDateOnly(p.paid_date)}` : "";
    return { key, label, state: "done", detail: `Paid ${money(paymentTotal(p))} by ${methodLabel(p.method)}${when}.` };
  }
  if (p && outcomeUnknown(p)) {
    return {
      key,
      label,
      state: "attention",
      detail: `No answer from the payment processor for ${money(paymentTotal(p))}. It may or may not have gone through. Charge again to check it: the same charge is repeated, never a second one.`,
    };
  }
  if (p?.status === "pending") {
    // A pending row nobody has charged yet is only scheduled; one with a Stripe charge behind it is really processing.
    return {
      key,
      label,
      state: "waiting",
      detail: p.stripe_payment_intent_id
        ? `Processing ${money(paymentTotal(p))} by ${methodLabel(p.method)}. A bank debit can take a few business days.`
        : `Scheduled ${money(paymentTotal(p))}. Not charged yet.`,
    };
  }
  if (receivedByHand) {
    return {
      key,
      label,
      state: "manual",
      detail:
        p?.status === "failed"
          ? "Marked received by hand. A charge was declined and no payment is recorded."
          : p?.status === "refunded"
            ? "Marked received by hand. The recorded payment was refunded."
            : "Marked received by hand. No payment is recorded in the system.",
    };
  }
  if (pickedUp) {
    // Picked up before the system collected this step: nothing is owed here, so nothing is waited for.
    return { key, label, state: "off", detail: "Not collected through the system. The equipment has already been picked up." };
  }
  if (p?.status === "failed") {
    const reason = p.failure_message ? ` ${p.failure_message}` : "";
    return { key, label, state: "attention", detail: `Declined: ${money(paymentTotal(p))}.${reason}` };
  }
  if (p?.status === "refunded") {
    return { key, label, state: "attention", detail: `Refunded: ${money(paymentTotal(p))}. Nothing is collected for this step now.` };
  }
  const amount = due ? ` (${money(due.bank)} by bank, ${money(due.card)} by card)` : "";
  return { key, label, state: "waiting", detail: `Not collected yet${amount}.` };
}

export function computeApplicationProgress(application: Application): ApplicationProgress {
  const lease = application.lease_agreement;
  const payments = lease?.payments ?? [];
  const pending = application.customer?.status === "pending";

  // 1. Contract
  const contract = lease?.contract;
  const hadVoided = !!lease?.contracts?.some((c) => c.voided_at);
  const contractStep: ProgressStep = contract
    ? { key: "contract", label: "Contract signed", state: "done", detail: `Signed ${new Date(contract.signed_at).toLocaleDateString()}.` }
    : {
        key: "contract",
        label: "Contract signed",
        state: "waiting",
        detail: `${hadVoided ? "The previous signature was voided. " : ""}Waiting for the customer to sign${
          pending ? ". They have no login yet, so they sign from the emailed link." : " in their portal."
        }`,
      };

  // 2. AutoPay: needs a bank account and a card
  const bank = !!lease?.stripe_bank_payment_method_id;
  const card = !!lease?.stripe_card_payment_method_id;
  let autopayStep: ProgressStep;
  if (!lease || !lease.autopay_enabled) {
    autopayStep = { key: "autopay", label: "AutoPay set up", state: "off", detail: "AutoPay is off for this lease." };
  } else if (lease.autopay_paused_at) {
    // Paused is a staff decision that needs a person, whichever methods are on file.
    autopayStep = { key: "autopay", label: "AutoPay set up", state: "attention", detail: "Automatic charging is paused on this lease." };
  } else if (bank && card) {
    const primary = lease.autopay_primary_method === "card" ? "card" : "bank account";
    autopayStep = { key: "autopay", label: "AutoPay set up", state: "done", detail: `Bank account and card are on file. Charged first: ${primary}.` };
  } else if (bank || card) {
    // The automatic charger works with just one saved method (a card on its own is a normal choice, and
    // staff can add it for the customer), so one is enough. The other only adds a fallback.
    autopayStep = {
      key: "autopay",
      label: "AutoPay set up",
      state: "done",
      detail: bank
        ? "Bank account on file, so AutoPay can charge it. A backup card is optional."
        : "Card on file, so AutoPay can charge it. A bank account is optional (it would be charged first, at the lower price).",
    };
  } else {
    autopayStep = {
      key: "autopay",
      label: "AutoPay set up",
      state: "waiting",
      detail: "No bank account or card yet. The customer adds one on their AutoPay page, or staff can add a card for them.",
    };
  }

  // 3 and 4. The two charges
  const pickedUp = application.status === "finished";
  const depositStep = paymentStep("deposit", "Security deposit", payments, application.deposit_received, lease?.pricing?.deposit, pickedUp);
  const balanceStep = paymentStep(
    "pickup_balance",
    "Tracking fee and first month",
    payments,
    application.pickup_balance_received,
    lease?.pricing?.pickup_balance,
    pickedUp,
  );

  // 5. Pickup
  const deliveredStep: ProgressStep =
    application.status === "finished"
      ? {
          key: "delivered",
          label: "Picked up",
          state: "done",
          detail: lease?.equipment_unit?.delivery_date ? `Delivered ${formatDateOnly(lease.equipment_unit.delivery_date)}.` : "Delivered.",
        }
      : {
          key: "delivered",
          label: "Picked up",
          state: "waiting",
          detail: application.status === "waiting_delivery" ? "Ready for pickup." : "Not ready for pickup yet.",
        };

  const steps = [contractStep, autopayStep, depositStep, balanceStep, deliveredStep];
  return { steps, nextStep: nextStepFor(application, steps) };
}

function nextStepFor(application: Application, steps: ProgressStep[]): string {
  const byKey = Object.fromEntries(steps.map((s) => [s.key, s])) as Record<ProgressStep["key"], ProgressStep>;
  const open = (s: ProgressStep) => s.state === "waiting" || s.state === "attention";

  if (open(byKey.contract)) return "Waiting for the customer to sign the contract.";

  // A declined or refunded charge is the first thing staff must see: it would otherwise hide behind a missing AutoPay method.
  if (byKey.deposit.state === "attention") {
    return byKey.deposit.detail.startsWith("Refunded")
      ? "The deposit was refunded. Check whether a new deposit is needed."
      : byKey.deposit.detail.startsWith("No answer")
        ? "The deposit charge got no answer from the payment processor. Charge it again here to check it, or ask the customer to press pay again."
        : "The deposit charge was declined. Ask the customer to try again, or charge it here once they agree.";
  }
  if (byKey.pickup_balance.state === "attention") {
    return byKey.pickup_balance.detail.startsWith("Refunded")
      ? "The tracking fee and first month was refunded. Check whether it needs to be collected again."
      : byKey.pickup_balance.detail.startsWith("No answer")
        ? "The tracking fee and first month charge got no answer from the payment processor. Ask the customer to press pay again to check it."
        : "The tracking fee and first month charge was declined. Ask the customer to try again.";
  }

  if (open(byKey.autopay)) {
    if (byKey.autopay.state === "attention") return "AutoPay is paused on this lease. Resume it when the customer is ready.";
    return "Waiting for the customer to add a bank account or a card for AutoPay.";
  }

  if (open(byKey.deposit)) return byKey.deposit.detail.startsWith("Processing") ? "The deposit bank debit is still processing." : "Waiting for the security deposit.";

  if (open(byKey.pickup_balance)) {
    return byKey.pickup_balance.detail.startsWith("Processing")
      ? "The tracking fee and first month bank debit is still processing."
      : "Waiting for the tracking fee and first month.";
  }

  // A scheduled monthly payment that failed needs a person, even when every step above is done.
  if (application.lease_agreement?.payments?.some((p) => p.type === "rental" && p.status === "failed")) {
    return "A monthly payment failed (see Payments below). Once the customer has fixed their account, use Retry charge on the Payments screen.";
  }

  if (application.status === "waiting_deposit") return "Everything is collected. Click Mark Deposit Received to move this to Waiting on Delivery.";
  if (application.status === "waiting_delivery") return "Ready for pickup. Click Mark Delivered & Paid when the customer takes the equipment.";
  return "Nothing left to do. The lease is active.";
}
