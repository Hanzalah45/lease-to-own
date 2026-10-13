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

/** The first scheduled monthly payment when the first month was really collected inside the tracking fee and first month charge. */
export function firstMonthMirror(payments: Payment[]): Payment | undefined {
  const paid = payments.filter((p) => p.status === "paid");
  if (!paid.some((p) => p.type === "pickup_balance")) return undefined;

  return paid
    .filter((p) => p.type === "rental" && !p.stripe_payment_intent_id)
    .sort((a, b) => a.due_date.localeCompare(b.due_date) || a.id - b.id)[0];
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
): ProgressStep {
  const p = latestOfType(payments, key);

  if (p?.status === "paid") {
    const when = p.paid_date ? ` on ${formatDateOnly(p.paid_date)}` : "";
    return { key, label, state: "done", detail: `Paid ${money(paymentTotal(p))} by ${methodLabel(p.method)}${when}.` };
  }
  if (p?.status === "pending") {
    return {
      key,
      label,
      state: "waiting",
      detail: `Processing ${money(paymentTotal(p))} by ${methodLabel(p.method)}. A bank debit can take a few business days.`,
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
          : "Marked received by hand. No payment is recorded in the system.",
    };
  }
  if (p?.status === "failed") {
    const reason = p.failure_message ? ` ${p.failure_message}` : "";
    return { key, label, state: "attention", detail: `Declined: ${money(paymentTotal(p))}.${reason}` };
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
  } else if (bank && card) {
    const primary = lease.autopay_primary_method === "card" ? "card" : "bank account";
    autopayStep = lease.autopay_paused_at
      ? { key: "autopay", label: "AutoPay set up", state: "attention", detail: "Bank account and card are on file, but automatic charging is paused on this lease." }
      : { key: "autopay", label: "AutoPay set up", state: "done", detail: `Bank account and card are on file. Charged first: ${primary}.` };
  } else if (bank || card) {
    autopayStep = {
      key: "autopay",
      label: "AutoPay set up",
      state: "waiting",
      detail: `${bank ? "Bank account added" : "Card added"}, ${bank ? "card" : "bank account"} still missing.`,
    };
  } else {
    autopayStep = {
      key: "autopay",
      label: "AutoPay set up",
      state: "waiting",
      detail: "No bank account or card yet. The customer adds both on their AutoPay page.",
    };
  }

  // 3 and 4. The two charges
  const depositStep = paymentStep("deposit", "Security deposit", payments, application.deposit_received, lease?.pricing?.deposit);
  const balanceStep = paymentStep(
    "pickup_balance",
    "Tracking fee and first month",
    payments,
    application.pickup_balance_received,
    lease?.pricing?.pickup_balance,
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

  if (open(byKey.autopay)) {
    if (byKey.autopay.state === "attention") return "AutoPay is paused on this lease. Resume it when the customer is ready.";
    return byKey.autopay.detail.startsWith("Bank account added")
      ? "Waiting for the customer to add a card for AutoPay."
      : byKey.autopay.detail.startsWith("Card added")
        ? "Waiting for the customer to add a bank account for AutoPay."
        : "Waiting for the customer to add a bank account and a card for AutoPay.";
  }

  if (byKey.deposit.state === "attention") return "The deposit charge was declined. Ask the customer to try again, or charge it here once they agree.";
  if (open(byKey.deposit)) return byKey.deposit.detail.startsWith("Processing") ? "The deposit bank debit is still processing." : "Waiting for the security deposit.";

  if (byKey.pickup_balance.state === "attention") return "The tracking fee and first month charge was declined. Ask the customer to try again.";
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
