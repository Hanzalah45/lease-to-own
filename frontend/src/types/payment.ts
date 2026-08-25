export type PaymentMethod = "ach" | "card" | "cash" | "other";
export type PaymentStatus = "pending" | "paid" | "failed" | "refunded";

export interface Payment {
  id: number;
  lease_agreement_id: number;
  amount: string;
  due_date: string;
  paid_date: string | null;
  method: PaymentMethod | null;
  status: PaymentStatus;
  recorded_by: number | null;
}
