import { API_BASE_URL, apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";

/** One time a receipt was emailed (client, Joel, 2026-10-19 checklist, section 8). "Delivered" is not known: only that the mail service took it. */
export interface ReceiptSend {
  id: number;
  email: string;
  /** auto: sent when the money landed. manual: staff sent it again. */
  trigger: "auto" | "manual";
  outcome: "sent" | "failed";
  error: string | null;
  sent_at: string;
  sent_by?: { id: number; name: string } | null;
}

export interface ReceiptLine {
  label: string;
  amount: number;
}

/** What the receipt said when it was written. Never changes. */
export interface ReceiptDetails {
  description: string;
  method_label: string;
  paid_on: string;
  lines: ReceiptLine[];
  total: number;
  balance?: { price: number; paid_to_date?: number; remaining?: number; paid_in_full?: boolean; refunded_to_date?: number };
}

/** A receipt as staff see it. */
export interface Receipt {
  id: number;
  receipt_number: string;
  kind: "payment" | "refund";
  lease_agreement_id: number;
  payment_id: number | null;
  /** What came in (card fee included) or went back. */
  amount: string;
  details: ReceiptDetails;
  issued_at: string;
  has_copy: boolean;
  sends?: ReceiptSend[];
}

/** A receipt as the customer sees it: the list, with a download. */
export interface MyReceipt {
  id: number;
  receipt_number: string;
  kind: "payment" | "refund";
  payment_id: number | null;
  amount: string;
  description: string | null;
  issued_at: string;
}

export async function listReceipts(leaseAgreementId: number | string): Promise<Receipt[]> {
  const data = await apiFetch<{ data: Receipt[] }>(`/admin/lease-agreements/${leaseAgreementId}/receipts`, { token: getToken() });
  return data.data;
}

/** Staff: sends a receipt again, to the customer's address or another. */
export async function sendReceiptAgain(receiptId: number | string, email?: string): Promise<{ receipt: Receipt; mailed: boolean }> {
  const data = await apiFetch<{ data: Receipt; mailed: boolean }>(`/admin/payment-receipts/${receiptId}/send`, {
    method: "POST",
    token: getToken(),
    body: email ? { email } : {},
  });
  return { receipt: data.data, mailed: data.mailed };
}

export async function listMyReceipts(): Promise<MyReceipt[]> {
  const data = await apiFetch<{ data: MyReceipt[] }>("/customer/receipts", { token: getToken() });
  return data.data;
}

/** Downloads the stored PDF copy of a receipt (staff: `staff`, customer: otherwise). */
export async function downloadReceipt(receiptId: number | string, receiptNumber: string, staff: boolean): Promise<void> {
  const path = staff ? `/admin/payment-receipts/${receiptId}/download` : `/customer/receipts/${receiptId}/download`;
  const response = await fetch(`${API_BASE_URL}${path}`, { headers: { Authorization: `Bearer ${getToken()}` } });
  if (!response.ok) throw new Error("Could not download the receipt.");

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `Receipt-${receiptNumber}.pdf`;
  link.click();
  URL.revokeObjectURL(url);
}
