import { API_BASE_URL, apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { Payment, PaymentAuditEntry, PaymentNote } from "@/types/lease-agreement";

export async function listMyPayments(): Promise<Payment[]> {
  const data = await apiFetch<{ data: Payment[] }>("/customer/payments", { token: getToken() });
  return data.data;
}

export async function listPayments(leaseAgreementId?: number | string): Promise<Payment[]> {
  const query = leaseAgreementId ? `?lease_agreement_id=${leaseAgreementId}` : "";
  const data = await apiFetch<{ data: Payment[] }>(`/admin/payments${query}`, { token: getToken() });
  return data.data;
}

/** Staff: stop automatic charging on one lease until resumed. */
export async function pauseAutopay(leaseAgreementId: number | string): Promise<string | null> {
  const data = await apiFetch<{ data: { autopay_paused_at: string | null } }>(`/admin/lease-agreements/${leaseAgreementId}/autopay/pause`, {
    method: "POST",
    token: getToken(),
  });
  return data.data.autopay_paused_at;
}

export async function resumeAutopay(leaseAgreementId: number | string): Promise<void> {
  await apiFetch(`/admin/lease-agreements/${leaseAgreementId}/autopay/resume`, { method: "POST", token: getToken() });
}

/** Staff: charge a failed monthly payment again (a fresh round, primary method first). */
export async function retryAutopayCharge(paymentId: number | string): Promise<Payment> {
  const data = await apiFetch<{ data: { payment: Payment } }>(`/admin/payments/${paymentId}/retry-autopay`, {
    method: "POST",
    token: getToken(),
  });
  return data.data.payment;
}

/**
 * Staff: charge the security deposit to the customer's saved bank account or
 * card after the customer agreed. The server refuses (409) when the total no
 * longer matches the one shown, and when the deposit is already paid or in flight.
 */
export async function chargeDepositAsStaff(
  applicationId: number | string,
  choice: { method: "bank" | "card"; expectedTotalCents: number; customerAgreed: boolean },
): Promise<Payment> {
  return chargeAsStaff(applicationId, "charge-deposit", choice);
}

/** Staff: the same for the tracking fee and first month, which a customer who already took the mower may still owe. */
export async function chargePickupBalanceAsStaff(
  applicationId: number | string,
  choice: { method: "bank" | "card"; expectedTotalCents: number; customerAgreed: boolean },
): Promise<Payment> {
  return chargeAsStaff(applicationId, "charge-pickup-balance", choice);
}

async function chargeAsStaff(
  applicationId: number | string,
  path: "charge-deposit" | "charge-pickup-balance",
  choice: { method: "bank" | "card"; expectedTotalCents: number; customerAgreed: boolean },
): Promise<Payment> {
  const data = await apiFetch<{ data: { payment: Payment } }>(`/admin/applications/${applicationId}/${path}`, {
    method: "POST",
    token: getToken(),
    body: {
      method: choice.method,
      expected_total_cents: choice.expectedTotalCents,
      customer_agreed: choice.customerAgreed,
    },
  });
  return data.data.payment;
}

/** What staff can give as the reason for a refund (the server holds the same list). */
export const REFUND_REASONS = [
  { value: "customer_request", label: "The customer asked for a refund" },
  { value: "overpayment", label: "The customer paid too much" },
  { value: "duplicate", label: "The customer was charged twice" },
  { value: "posted_in_error", label: "The payment was recorded in error" },
  { value: "returned_equipment", label: "The equipment was returned" },
  { value: "other", label: "Other, explained in the note" },
] as const;

export type RefundReason = (typeof REFUND_REASONS)[number]["value"];

/** A reason in words, including the two the system gives itself. */
export function refundReasonLabel(reason: string): string {
  if (reason === "refunded_in_stripe") return "Refunded in Stripe";
  if (reason === "chargeback") return "Chargeback lost";

  return REFUND_REASONS.find((r) => r.value === reason)?.label ?? reason;
}

/**
 * Staff: gives money back for a paid payment, all of it or part. A payment taken through Stripe is refunded through Stripe to the
 * card or bank account it came from; one recorded by hand is only recorded as refunded. Returns the payment with its refunds.
 */
export async function refundPayment(paymentId: number | string, body: { amount: number; reason: RefundReason; note?: string; /** A payment paid in parts is refunded one part at a time. */ partId?: number }): Promise<Payment> {
  const data = await apiFetch<{ data: Payment }>(`/admin/payments/${paymentId}/refunds`, {
    method: "POST",
    token: getToken(),
    body: { amount: body.amount, reason: body.reason, note: body.note || undefined, part_id: body.partId },
  });
  return data.data;
}

/** Staff: cancels a refund Stripe never answered, after checking in Stripe that it was not made. */
export async function cancelUnansweredRefund(refundId: number | string): Promise<Payment> {
  const data = await apiFetch<{ data: Payment }>(`/admin/payment-refunds/${refundId}/cancel`, { method: "POST", token: getToken() });
  return data.data;
}

/** How staff take a piece of a payment: received in the office, or charged to the customer's saved bank account or card. */
export type TakeMethod = "cash" | "check" | "other" | "bank" | "card";

export interface TakePaymentBody {
  method: TakeMethod;
  /** The bank price of the piece. */
  amount: number;
  note?: string;
  /** A charge to a saved method: the total shown on screen, and that the customer agreed to it. */
  expectedTotalCents?: number;
  customerAgreed?: boolean;
}

function takeBody(body: TakePaymentBody): Record<string, unknown> {
  return {
    method: body.method,
    amount: body.amount,
    note: body.note || undefined,
    expected_total_cents: body.expectedTotalCents,
    customer_agreed: body.customerAgreed || undefined,
  };
}

/**
 * Staff: take a payment toward something that is due, in whole or in part (client, Joel, 2026-10-19 checklist, sections 1 and 7): cash,
 * a check or another method received in the office, or a charge to the saved bank account or card. Returns the payment with its pieces.
 */
export async function takePayment(paymentId: number | string, body: TakePaymentBody): Promise<Payment> {
  const data = await apiFetch<{ data: Payment }>(`/admin/payments/${paymentId}/parts`, { method: "POST", token: getToken(), body: takeBody(body) });
  return data.data;
}

/** The same for the deposit or the tracking fee and first month of an application, which may have no payment row yet. */
export async function takeApplicationPayment(applicationId: number | string, type: "deposit" | "pickup_balance", body: TakePaymentBody): Promise<Payment> {
  const data = await apiFetch<{ data: Payment }>(`/admin/applications/${applicationId}/payment-parts`, {
    method: "POST",
    token: getToken(),
    body: { type, ...takeBody(body) },
  });
  return data.data;
}

/** Staff: cancels a charge for a piece that Stripe never answered, after checking in Stripe that it was not made. */
export async function cancelUnansweredPart(partId: number | string): Promise<Payment> {
  const data = await apiFetch<{ data: Payment }>(`/admin/payment-parts/${partId}/cancel`, { method: "POST", token: getToken() });
  return data.data;
}

export const NOTE_KINDS = [
  { value: "general", label: "Note" },
  { value: "failed", label: "Failed payment" },
  { value: "refund", label: "Refund" },
  { value: "chargeback", label: "Chargeback" },
] as const;

export type NoteKind = (typeof NOTE_KINDS)[number]["value"];

/** Staff: adds an internal note to a payment. Customers never see it. */
export async function addPaymentNote(paymentId: number | string, body: { kind: NoteKind; body: string }): Promise<PaymentNote> {
  const data = await apiFetch<{ data: PaymentNote }>(`/admin/payments/${paymentId}/notes`, { method: "POST", token: getToken(), body });
  return data.data;
}

/** Staff: the notes and the audit trail of one payment, newest first. */
export async function getPaymentHistory(paymentId: number | string): Promise<{ notes: PaymentNote[]; audit: PaymentAuditEntry[] }> {
  const data = await apiFetch<{ data: { notes: PaymentNote[]; audit: PaymentAuditEntry[] } }>(`/admin/payments/${paymentId}/history`, { token: getToken() });
  return data.data;
}

export interface AutopayLogFilters {
  status?: "succeeded" | "failed" | "processing" | "initiated" | "";
  method?: "bank" | "card" | "";
  startedBy?: "autopay" | "customer" | "";
  from?: string;
  to?: string;
}

/** One try at collecting a monthly payment through Stripe, as the AutoPay log lists it. */
export interface AutopayLogEntry {
  id: number;
  created_at: string;
  attempt_no: number;
  round: number;
  method: "bank" | "card";
  amount: number;
  fee: number;
  status: "initiated" | "processing" | "succeeded" | "failed";
  failure_code: string | null;
  failure_message: string | null;
  started_by: "autopay" | "customer";
  payment_id: number;
  payment_type: string | null;
  due_date: string | null;
  lease_agreement_id: number | null;
  application_id: number | null;
  customer: string | null;
}

export interface AutopayLogPage {
  data: AutopayLogEntry[];
  current_page: number;
  last_page: number;
  total: number;
}

/** Staff: every try at collecting a monthly payment through Stripe, newest first, 50 to a page. */
export async function listAutopayLog(filters: AutopayLogFilters, page: number): Promise<AutopayLogPage> {
  const query = new URLSearchParams({ page: String(page) });
  if (filters.status) query.set("status", filters.status);
  if (filters.method) query.set("method", filters.method);
  if (filters.startedBy) query.set("started_by", filters.startedBy);
  if (filters.from) query.set("from", filters.from);
  if (filters.to) query.set("to", filters.to);

  return apiFetch<AutopayLogPage>(`/admin/autopay-log?${query.toString()}`, { token: getToken() });
}

export interface AuditLogFilters {
  action?: string;
  who?: "staff" | "customer" | "system" | "";
  from?: string;
  to?: string;
  paymentId?: number | string;
  leaseId?: number | string;
}

export interface AuditLogPage {
  data: PaymentAuditEntry[];
  current_page: number;
  last_page: number;
  total: number;
}

/** Staff: the payment audit trail as one list, newest first, 50 to a page. */
export async function listAuditLog(filters: AuditLogFilters, page: number): Promise<AuditLogPage> {
  const query = new URLSearchParams({ page: String(page) });
  if (filters.action) query.set("action", filters.action);
  if (filters.who) query.set("who", filters.who);
  if (filters.from) query.set("from", filters.from);
  if (filters.to) query.set("to", filters.to);
  if (filters.paymentId) query.set("payment_id", String(filters.paymentId));
  if (filters.leaseId) query.set("lease_agreement_id", String(filters.leaseId));

  return apiFetch<AuditLogPage>(`/admin/payment-audit-log?${query.toString()}`, { token: getToken() });
}

export async function markPaymentStatus(
  id: number | string,
  status: "pending" | "paid" | "failed",
  method?: "ach" | "card" | "cash" | "check" | "other",
): Promise<Payment> {
  const data = await apiFetch<{ data: Payment }>(`/admin/payments/${id}`, {
    method: "PUT",
    token: getToken(),
    body: { status, method },
  });
  return data.data;
}

/** Staff: starts saving a card for a customer. Returns the Stripe client secret for the card form. */
export async function startCardSetupAsStaff(leaseAgreementId: number | string): Promise<string> {
  const data = await apiFetch<{ data: { client_secret: string } }>(`/admin/lease-agreements/${leaseAgreementId}/card/setup-intent`, {
    method: "POST",
    token: getToken(),
  });
  return data.data.client_secret;
}

/** Staff: saves the card that Stripe has just set up as the customer's card for AutoPay. The card number never reaches our server. */
export async function saveCardAsStaff(
  leaseAgreementId: number | string,
  setupIntentId: string,
): Promise<{ brand: string | null; last4: string | null; replaced: boolean }> {
  const data = await apiFetch<{ data: { card: { brand: string | null; last4: string | null }; replaced: boolean } }>(`/admin/lease-agreements/${leaseAgreementId}/card`, {
    method: "POST",
    token: getToken(),
    body: { setup_intent_id: setupIntentId, customer_agreed: true },
  });
  return { ...data.data.card, replaced: data.data.replaced };
}

/**
 * Staff: a short-lived page for the customer to link their own bank account on the office computer (their own bank login,
 * never seen by staff). The page does the saving; nothing is saved by this call.
 */
export async function startBankLinkAsStaff(leaseAgreementId: number | string): Promise<{ url: string; expires_in_minutes: number }> {
  const data = await apiFetch<{ data: { url: string; expires_in_minutes: number } }>(`/admin/lease-agreements/${leaseAgreementId}/bank-link`, {
    method: "POST",
    token: getToken(),
  });
  return data.data;
}

/** What staff may see about a saved AutoPay method: never more than brand, last four and expiry (card) or bank name and last four. */
export interface StaffMethodInfo {
  /** True when Stripe could not be reached, so the details are not known right now. */
  unavailable?: boolean;
  brand?: string | null;
  last4?: string | null;
  exp_month?: number | null;
  exp_year?: number | null;
  funding?: string | null;
  bank_name?: string | null;
  account_type?: string | null;
}

/** A saved method that is locked: still on file, never charged (fraud, a dispute, a hard decline). */
export interface MethodLock {
  locked_at: string;
  reason: string | null;
}

export interface StaffAutopayMethods {
  autopay_enabled: boolean;
  primary: "ach" | "card" | null;
  bank: StaffMethodInfo | null;
  /** A bank account typed in by hand that is waiting for its two small test deposits to be confirmed: not yet chargeable. */
  bank_pending?: { since: string; arrives_by: string | null } | null;
  card: StaffMethodInfo | null;
  bank_lock: MethodLock | null;
  card_lock: MethodLock | null;
  /** A chargeback froze this lease: AutoPay is paused and the method it was paid with is locked until it is decided. */
  chargeback_frozen: boolean;
  /** The card fee in basis points (300 is 3%), for pricing a charge on screen the way the server prices it. */
  card_fee_bps: number;
  /** The customer's request to turn AutoPay off, waiting for its day. */
  autopay_revocation: { requested_at: string; effective_on: string; via: "portal" | "staff" } | null;
}

/** Staff: records that the customer asked in writing to turn AutoPay off. The same three business days run, then the system switches it off. */
export async function recordAutopayRevocation(leaseAgreementId: number | string): Promise<{ effective_on: string }> {
  const data = await apiFetch<{ data: { effective_on: string } }>(`/admin/lease-agreements/${leaseAgreementId}/autopay/revocation`, {
    method: "POST",
    token: getToken(),
    body: { customer_asked_in_writing: true },
  });
  return data.data;
}

/** Staff: locks a saved bank account or card (a reason is required), or unlocks it. A locked method is never charged. */
export async function setMethodLock(
  leaseAgreementId: number | string,
  body: { type: "bank" | "card"; locked: boolean; reason?: string },
): Promise<{ bank_lock: MethodLock | null; card_lock: MethodLock | null }> {
  const data = await apiFetch<{ data: { bank_lock: MethodLock | null; card_lock: MethodLock | null } }>(`/admin/lease-agreements/${leaseAgreementId}/method-lock`, {
    method: "POST",
    token: getToken(),
    body,
  });
  return data.data;
}

/** Staff: the bank account and card AutoPay would use, as Stripe describes them. */
export async function getAutopayMethodsAsStaff(leaseAgreementId: number | string): Promise<StaffAutopayMethods> {
  const data = await apiFetch<{ data: StaffAutopayMethods }>(`/admin/lease-agreements/${leaseAgreementId}/autopay-methods`, {
    token: getToken(),
  });
  return data.data;
}

export interface PayMyPaymentResult {
  payment: Payment;
  /** paid, processing (a bank debit that settles in days), failed, or requires_action (the card wants an extra confirmation on screen). */
  status: "paid" | "processing" | "failed" | "requires_action";
  message: string | null;
  client_secret: string | null;
}

/**
 * Customer: pays a due monthly payment with a saved bank account or card ("Pay now"). The server refuses (409) when the
 * total no longer matches the one shown, and never charges twice for a press that got no answer.
 */
export async function payMyPayment(
  paymentId: number | string,
  choice: { method: "bank" | "card"; expectedTotalCents: number; /** Pay only part of what is due: the amount, at the bank price. */ amount?: number },
): Promise<PayMyPaymentResult> {
  const data = await apiFetch<{ data: PayMyPaymentResult }>(`/customer/payments/${paymentId}/pay`, {
    method: "POST",
    token: getToken(),
    body: { method: choice.method, expected_total_cents: choice.expectedTotalCents, amount: choice.amount },
  });
  return data.data;
}

/** One movement of a lease's Additional Funds balance, newest first in a list. */
export interface AdditionalFundsMovement {
  id: number;
  kind: "added" | "reversed" | "refunded" | "repairs" | "adjusted";
  /** Signed: money in is positive, money out is negative. */
  amount: string;
  balance_after: string;
  description: string | null;
  created_at: string;
  /** Staff views only. */
  created_by?: { id: number; name: string } | null;
}

/** What a customer can do toward the early purchase price right now (contract Section 19, "Additional Funds"). */
export interface ExtraPaymentOptions {
  available: boolean;
  /** Why an extra payment cannot be made right now, in words for the customer. */
  blocker: string | null;
  min: number;
  /** The whole early purchase price: paying that much leaves nothing to pay. */
  max: number;
  /** The early purchase price today. */
  epo_today: number;
  available_methods: Array<"bank" | "card">;
  card_fee_percent: number;
  /** What the customer has put toward the early purchase price, and every movement of it. */
  additional_funds?: number;
  history?: AdditionalFundsMovement[];
}

/** Customer: whether and how much they can pay extra toward the early purchase price. */
export async function getExtraPaymentOptions(leaseAgreementId: number | string): Promise<ExtraPaymentOptions> {
  const data = await apiFetch<{ data: ExtraPaymentOptions }>(`/customer/lease-agreements/${leaseAgreementId}/extra-payment`, {
    token: getToken(),
  });
  return data.data;
}

/**
 * Customer: pays an extra amount toward the early purchase price with a saved bank account or card. The server refuses
 * (409) when the total no longer matches the one shown and never charges twice for a press that got no answer.
 */
export async function payExtra(
  leaseAgreementId: number | string,
  choice: { amount: number; method: "bank" | "card"; expectedTotalCents: number },
): Promise<PayMyPaymentResult & { epo_today: number }> {
  const data = await apiFetch<{ data: PayMyPaymentResult & { epo_today: number } }>(`/customer/lease-agreements/${leaseAgreementId}/extra-payment`, {
    method: "POST",
    token: getToken(),
    body: { amount: choice.amount, method: choice.method, expected_total_cents: choice.expectedTotalCents },
  });
  return data.data;
}

/** Staff: records an extra payment the customer made in the office (cash, check or other) toward the early purchase price. */
export async function recordExtraPaymentAsStaff(
  leaseAgreementId: number | string,
  body: { amount: number; method: "cash" | "check" | "other" },
): Promise<{ payment: Payment; epo_today: number }> {
  const data = await apiFetch<{ data: { payment: Payment; epo_today: number } }>(`/admin/lease-agreements/${leaseAgreementId}/extra-payment`, {
    method: "POST",
    token: getToken(),
    body,
  });
  return data.data;
}

/** Staff: the Additional Funds of a lease (contract Section 19): balance, history, and whether they can be applied to repairs or refunded. */
export interface AdditionalFundsView {
  balance: number;
  /** The balance less any refund still waiting for the bank. */
  available: number;
  epo_today: number;
  entries: AdditionalFundsMovement[];
  /** Why funds cannot be applied to repairs right now, or null when they can. */
  repairs_blocker: string | null;
  refund_blocker: string | null;
  pending_refunds: number;
}

export async function getAdditionalFunds(leaseAgreementId: number | string): Promise<AdditionalFundsView> {
  const data = await apiFetch<{ data: AdditionalFundsView }>(`/admin/lease-agreements/${leaseAgreementId}/additional-funds`, { token: getToken() });
  return data.data;
}

/** Staff: applies part or all of the Additional Funds to what repairs cost (contract Section 19: repairs first). */
export async function applyFundsToRepairs(leaseAgreementId: number | string, body: { amount: number; description: string }): Promise<AdditionalFundsView> {
  const data = await apiFetch<{ data: AdditionalFundsView }>(`/admin/lease-agreements/${leaseAgreementId}/additional-funds/repairs`, { method: "POST", token: getToken(), body });
  return data.data;
}

/** Staff: gives Additional Funds back (all that is left when no amount is given), each through the extra payment it came from. */
export async function refundAdditionalFunds(leaseAgreementId: number | string, body: { amount?: number; note?: string }): Promise<AdditionalFundsView> {
  const data = await apiFetch<{ data: AdditionalFundsView }>(`/admin/lease-agreements/${leaseAgreementId}/additional-funds/refund`, {
    method: "POST",
    token: getToken(),
    body: { amount: body.amount, note: body.note || undefined },
  });
  return data.data;
}

export type LeaseEndReason = "returned" | "purchased" | "other";

/**
 * Staff: ends a lease that was already picked up (equipment returned, early purchase completed, or another reason). AutoPay is
 * switched off, the monthly payments nobody has been charged for are removed and the equipment is released. Money already
 * collected is not refunded here.
 */
export async function endLeaseAsStaff(
  leaseAgreementId: number | string,
  body: { reason: LeaseEndReason; note?: string; taxCollected?: boolean },
): Promise<{ removed: number; late_fees_left: number; ended_at: string }> {
  const data = await apiFetch<{ data: { removed: number; late_fees_left: number; ended_at: string } }>(`/admin/lease-agreements/${leaseAgreementId}/end`, {
    method: "POST",
    token: getToken(),
    body: { reason: body.reason, note: body.note || undefined, tax_collected: body.taxCollected ?? undefined },
  });
  return data.data;
}

/** One month of the sales tax and payments report: what was collected, with the sales tax inside it. */
export interface SalesTaxReportMonth {
  month: string;
  label: string;
  payments: number;
  /** How many refunds went back in the month, and what they came to. The figures below already have them taken off. */
  refunds: number;
  refunded: number;
  /** Rent and loss damage waiver together, before tax: the figure the sales tax return needs. */
  taxable_sales: number;
  /** The same taxable sales told apart: the rent, and the loss damage waiver (LDW) inside them. */
  rent: number;
  ldw: number;
  sales_tax: number;
  tracking_fees: number;
  deposits: number;
  late_fees: number;
  extra_payments: number;
  card_fees: number;
  total_collected: number;
}

export interface SalesTaxReport {
  from: string;
  to: string;
  months: SalesTaxReportMonth[];
  totals: Omit<SalesTaxReportMonth, "month" | "label">;
  /** Total collected by payment method (ach, card, cash, check, other, unknown). */
  by_method: Record<string, number>;
}

function reportQuery(from?: string, to?: string): string {
  const query = new URLSearchParams();
  if (from) query.set("from", from);
  if (to) query.set("to", to);
  const text = query.toString();

  return text ? `?${text}` : "";
}

/** Staff: money collected between two dates (a whole year by default), by month, with the sales tax inside it. */
export async function getSalesTaxReport(from?: string, to?: string): Promise<SalesTaxReport> {
  const data = await apiFetch<{ data: SalesTaxReport }>(`/admin/reports/sales-tax${reportQuery(from, to)}`, { token: getToken() });
  return data.data;
}

/** Staff: downloads every payment collected between two dates as a spreadsheet file, for the books. */
export async function downloadPaymentsCsv(from: string, to: string): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/admin/reports/payments.csv${reportQuery(from, to)}`, {
    headers: { Authorization: `Bearer ${getToken()}` },
  });
  if (!response.ok) throw new Error("Could not download the report.");

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `payments-${from}-to-${to}.csv`;
  link.click();
  URL.revokeObjectURL(url);
}
