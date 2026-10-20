import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";

/**
 * One email the system sent to one address (client, Joel, 2026-10-13).
 * "sent" means the email service accepted it; it does not yet say the message
 * reached the inbox (delivery results come later).
 */
export interface EmailLogEntry {
  id: number;
  user_id: number | null;
  to_address: string;
  subject: string | null;
  kind: string | null;
  /** Staff-friendly name of the kind of email, worked out on the server. */
  kind_label: string;
  status: "sending" | "sent" | "failed";
  error: string | null;
  /** What the mailbox provider did with the message after the email service accepted it. Null: no result yet, or not tracked. */
  delivery_status?: DeliveryStatus | null;
  delivered_at?: string | null;
  /** Why, in the provider's words: a bounce's type and reason, a delay's cause. */
  delivery_detail?: string | null;
  created_at: string;
  user?: { id: number; name: string; role?: string } | null;
}

/** delivered: reached the mailbox. bounced: the mailbox provider refused it. complaint: the person marked it as spam. delayed: still being tried. rejected: never left the email service. */
export type DeliveryStatus = "delivered" | "bounced" | "complaint" | "delayed" | "rejected";

/** The part of an email log row that says what happened to the message: also carried by every send of a receipt or a payment link. */
export interface DeliveryInfo {
  delivery_status: DeliveryStatus | null;
  delivered_at: string | null;
  delivery_detail: string | null;
}

export interface EmailLogPage {
  data: EmailLogEntry[];
  meta: { current_page: number; last_page: number; total: number };
}

export async function listEmailLogs(params: { search?: string; status?: string; page?: number }): Promise<EmailLogPage> {
  const query = new URLSearchParams();
  if (params.search) query.set("search", params.search);
  // "delivery:bounced" and the like filter by what happened after the email service accepted the message.
  if (params.status?.startsWith("delivery:")) query.set("delivery", params.status.slice("delivery:".length));
  else if (params.status) query.set("status", params.status);
  if (params.page && params.page > 1) query.set("page", String(params.page));
  const suffix = query.toString() ? `?${query.toString()}` : "";
  return apiFetch<EmailLogPage>(`/admin/email-logs${suffix}`, { token: getToken() });
}

export async function listCustomerEmails(customerId: number | string): Promise<EmailLogEntry[]> {
  const data = await apiFetch<{ data: EmailLogEntry[] }>(`/admin/customers/${customerId}/emails`, { token: getToken() });
  return data.data;
}
