export type OwnershipStatus = "leasing" | "owned";

export interface EquipmentUnit {
  id: number;
  model: string;
  serial_number: string;
  vin: string | null;
  condition_notes: string | null;
  delivery_date: string | null;
  expected_return_or_ownership_date: string | null;
  status: "in_stock" | "leased" | "returned" | "owned_by_customer";
}

export interface Payment {
  id: number;
  lease_agreement_id: number;
  amount: string;
  due_date: string;
  paid_date: string | null;
  method: "ach" | "card" | "cash" | "other" | null;
  status: "pending" | "paid" | "failed" | "refunded";
}

export interface LeaseAgreement {
  id: number;
  application_id: number;
  customer_id: number;
  equipment_unit_id: number | null;
  term_months: number;
  start_date: string;
  renewal_date: string;
  payment_due_day: string | null;
  autopay_enabled: boolean;
  monthly_rental_payment: string;
  sales_tax_rate: string;
  security_deposit: string;
  cash_price: string;
  total_rental_purchase_price: string;
  rental_payments_paid_to_date: string;
  additional_funds: string;
  ownership_status: OwnershipStatus;
  ldw_selected: boolean;
  ldw_amount: string | null;
  promo_code: string | null;
  promo_discount: string | null;
  created_at: string;
  updated_at: string;
  customer?: { id: number; name: string; email: string };
  equipment_unit?: EquipmentUnit | null;
  payments?: Payment[];
  /** Server-computed — see LeaseEngine on the backend. */
  sales_tax_amount: number;
  total_monthly_payment: number;
  payments_made: number;
  epo_today: number;
  /** Only present on the `show` endpoint, not `index`. */
  epo_schedule?: { month: number; value: number }[];
}
