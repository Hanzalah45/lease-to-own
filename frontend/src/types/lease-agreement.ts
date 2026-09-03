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
  service_records_count?: number;
  updated_by?: { id: number; name: string } | null;
}

export interface Contract {
  id: number;
  lease_agreement_id: number;
  signer_user_id: number;
  signer?: { id: number; name: string };
  signer_name: string | null;
  file_path: string | null;
  version: number;
  signed_at: string;
  external_provider: string | null;
  external_envelope_id: string | null;
  voided_at: string | null;
  void_reason: string | null;
  voided_by?: { id: number; name: string } | null;
}

export interface Payment {
  id: number;
  lease_agreement_id: number;
  amount: string;
  due_date: string;
  paid_date: string | null;
  method: "ach" | "card" | "cash" | "other" | null;
  status: "pending" | "paid" | "failed" | "refunded";
  recorded_by?: { id: number; name: string } | null;
  lease_agreement?: {
    id: number;
    application_id: number;
    autopay_enabled: boolean;
    customer?: { id: number; name: string; email: string };
  };
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
  updated_by?: { id: number; name: string } | null;
  customer?: { id: number; name: string; email: string };
  equipment_unit?: EquipmentUnit | null;
  payments?: Payment[];
  contract?: Contract | null;
  /** Full signature history including voided ones, newest first — admin views only. */
  contracts?: Contract[];
  /** Server-computed — see LeaseEngine on the backend. */
  sales_tax_amount: number;
  total_monthly_payment: number;
  payments_made: number;
  epo_today: number;
  /** Only present on the `show` endpoint, not `index`. */
  epo_schedule?: { month: number; value: number }[];
}
