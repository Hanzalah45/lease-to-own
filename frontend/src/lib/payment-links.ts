import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { PriceOption } from "@/types/lease-agreement";

/** Where a link is in its life (client, Joel, 2026-10-19 checklist, section 6). "expired" is what a link past its day reads as. */
export type PaymentLinkStatus = "created" | "sent" | "opened" | "processing" | "paid" | "failed" | "cancelled" | "expired";

export const LINK_STATUS_LABEL: Record<PaymentLinkStatus, string> = {
  created: "Not sent yet",
  sent: "Sent",
  opened: "Opened",
  processing: "Payment on its way",
  paid: "Paid",
  failed: "Payment did not go through",
  cancelled: "Cancelled",
  expired: "Expired",
};

/** Tailwind classes for the status chip. */
export const LINK_STATUS_CLASS: Record<PaymentLinkStatus, string> = {
  created: "bg-neutral-100 text-neutral-600",
  sent: "bg-blue-100 text-blue-700",
  opened: "bg-indigo-100 text-indigo-700",
  processing: "bg-amber-100 text-amber-700",
  paid: "bg-green-100 text-green-700",
  failed: "bg-red-100 text-red-700",
  cancelled: "bg-neutral-200 text-neutral-600",
  expired: "bg-neutral-200 text-neutral-600",
};

export interface PaymentLinkSend {
  id: number;
  email: string;
  outcome: "sent" | "failed";
  error: string | null;
  sent_at: string;
  sent_by?: { id: number; name: string } | null;
}

/** A link as staff see it. The secret is never part of it: it is only shown once, when the link is made or sent. */
export interface PaymentLink {
  id: number;
  lease_agreement_id: number;
  kind: "payment" | "extra";
  payment_id: number | null;
  amount: string;
  description: string;
  sent_to: string | null;
  status: Exclude<PaymentLinkStatus, "expired">;
  /** The status as a person reads it: "expired" once past its day. */
  effective_status: PaymentLinkStatus;
  expires_at: string;
  first_sent_at: string | null;
  last_sent_at: string | null;
  send_count: number;
  opened_at: string | null;
  paid_at: string | null;
  cancelled_at: string | null;
  failure_message: string | null;
  created_at: string;
  created_by?: { id: number; name: string } | null;
  sends?: PaymentLinkSend[];
}

export interface PaymentLinkMade {
  data: PaymentLink;
  /** The address to open the link. Shown once to staff; a lost one is replaced by sending the link again. */
  url: string;
  /** Whether the email was accepted by the mail service. Null when it was not sent. */
  mailed: boolean | null;
}

export async function listPaymentLinks(leaseAgreementId: number | string): Promise<PaymentLink[]> {
  const data = await apiFetch<{ data: PaymentLink[] }>(`/admin/lease-agreements/${leaseAgreementId}/payment-links`, { token: getToken() });
  return data.data;
}

export interface MakeLinkBody {
  /** The most the link takes. Left out: everything still due. */
  amount?: number;
  /** Email it now. */
  send?: boolean;
  /** Another address than the customer's own. */
  email?: string;
}

/** Staff: a link toward a payment that is due. */
export async function createPaymentLink(paymentId: number | string, body: MakeLinkBody): Promise<PaymentLinkMade> {
  return apiFetch<PaymentLinkMade>(`/admin/payments/${paymentId}/links`, { method: "POST", token: getToken(), body });
}

/** Staff: a link for an extra payment toward the early purchase price. */
export async function createExtraPaymentLink(leaseAgreementId: number | string, body: MakeLinkBody & { amount: number }): Promise<PaymentLinkMade> {
  return apiFetch<PaymentLinkMade>(`/admin/lease-agreements/${leaseAgreementId}/payment-links/extra`, { method: "POST", token: getToken(), body });
}

/** Staff: send the link again. The earlier address of the link stops working. */
export async function resendPaymentLink(linkId: number | string, email?: string): Promise<PaymentLinkMade> {
  return apiFetch<PaymentLinkMade>(`/admin/payment-links/${linkId}/resend`, { method: "POST", token: getToken(), body: email ? { email } : {} });
}

export async function cancelPaymentLink(linkId: number | string): Promise<PaymentLink> {
  const data = await apiFetch<{ data: PaymentLink }>(`/admin/payment-links/${linkId}/cancel`, { method: "POST", token: getToken() });
  return data.data;
}

// ---- the customer's page ----

/** What the page a link opens shows. */
export interface PublicPaymentLink {
  customer_name: string | null;
  description: string;
  kind: "payment" | "extra";
  status: PaymentLinkStatus;
  /** Why the link cannot be paid right now, in words for the customer. Null when it can. */
  blocker: string | null;
  /** The bank price, at most what is still due. */
  amount: number;
  prices: PriceOption;
  card_fee_bps: number;
  available_methods: Array<"bank" | "card">;
  /** Where a customer with nothing saved adds a bank account or a card first. */
  setup_url: string | null;
  expires_at: string;
}

export interface PayLinkResult {
  status: "paid" | "processing" | "failed" | "requires_action";
  message: string | null;
  client_secret: string | null;
  link: PublicPaymentLink;
}

/** The secret travels in the body, never in a URL that could be logged. */
export async function getPublicPaymentLink(token: string): Promise<PublicPaymentLink> {
  const data = await apiFetch<{ data: PublicPaymentLink }>("/pay-links/show", { method: "POST", body: { token } });
  return data.data;
}

export async function payPublicPaymentLink(token: string, choice: { method: "bank" | "card"; expectedTotalCents: number }): Promise<PayLinkResult> {
  const data = await apiFetch<{ data: PayLinkResult }>("/pay-links/pay", {
    method: "POST",
    body: { token, method: choice.method, expected_total_cents: choice.expectedTotalCents },
  });
  return data.data;
}
