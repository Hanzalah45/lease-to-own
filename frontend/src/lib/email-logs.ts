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
  created_at: string;
  user?: { id: number; name: string } | null;
}

export interface EmailLogPage {
  data: EmailLogEntry[];
  meta: { current_page: number; last_page: number; total: number };
}

export async function listEmailLogs(params: { search?: string; status?: string; page?: number }): Promise<EmailLogPage> {
  const query = new URLSearchParams();
  if (params.search) query.set("search", params.search);
  if (params.status) query.set("status", params.status);
  if (params.page && params.page > 1) query.set("page", String(params.page));
  const suffix = query.toString() ? `?${query.toString()}` : "";
  return apiFetch<EmailLogPage>(`/admin/email-logs${suffix}`, { token: getToken() });
}

export async function listCustomerEmails(customerId: number | string): Promise<EmailLogEntry[]> {
  const data = await apiFetch<{ data: EmailLogEntry[] }>(`/admin/customers/${customerId}/emails`, { token: getToken() });
  return data.data;
}
