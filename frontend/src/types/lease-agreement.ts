export type OwnershipStatus = "leasing" | "owned";

/** Fixed two-cycle billing (client, Joel, 2026-10-05): the 1st or the 15th of each month, no custom dates. */
export type BillingCycle = "1st" | "15th";

export interface BillingCyclePreview {
  cycle: BillingCycle;
  first_payment_date: string;
  first_payment_amount: number;
  second_payment_date: string;
  second_payment_amount: number;
  second_payment_prorated: boolean;
  days_until_second_payment: number;
  recurring_amount: number;
  recurring_day: number;
  /** Dual pricing (client, 2026-10-05): the same payments at the card price. */
  first_payment_card: number;
  second_payment_card: number;
  recurring_card: number;
}

/** One chargeable amount at both prices: bank (ACH) is the stored lease price, card is that plus the card fee. */
export interface PriceOption {
  bank: number;
  card: number;
  card_fee: number;
}

/** Dual pricing (client, Joel, 2026-10-05): every amount a customer pays, priced per charge on the server. */
export interface PricingSummary {
  card_fee_percent: number;
  deposit: PriceOption;
  pickup_balance: PriceOption;
  monthly: PriceOption;
  full: PriceOption;
}

export interface EquipmentUnit {
  id: number;
  model: string;
  serial_number: string;
  vin: string | null;
  condition_notes: string | null;
  delivery_date: string | null;
  expected_return_or_ownership_date: string | null;
  status: "in_stock" | "leased" | "returned" | "owned_by_customer";
  /** Phase 2 (GPS provider) placeholder — stored but not acted on yet. */
  gps_device_id: string | null;
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

/** One try at collecting a monthly payment automatically: the primary method first, then the other as a fallback. */
export interface PaymentAttempt {
  id: number;
  attempt_no: number;
  round: number;
  method: "bank" | "card";
  amount_cents: number;
  fee_cents: number;
  status: "initiated" | "processing" | "succeeded" | "failed";
  failure_code: string | null;
  failure_message: string | null;
}

export interface Payment {
  id: number;
  lease_agreement_id: number;
  type: "rental" | "late_fee" | "deposit" | "pickup_balance";
  late_fee_for_payment_id: number | null;
  /** The BANK price. The total actually charged is amount + card_fee_amount. */
  amount: string;
  /** The extra card fee charged on top (0 for a bank payment). */
  card_fee_amount: string;
  due_date: string;
  paid_date: string | null;
  method: "ach" | "card" | "cash" | "other" | null;
  status: "pending" | "paid" | "failed" | "refunded";
  recorded_by?: { id: number; name: string } | null;
  /** Automatic-charge history for a rental payment, oldest first (admin views only). */
  attempts?: PaymentAttempt[];
  lease_agreement?: {
    id: number;
    application_id: number;
    autopay_enabled: boolean;
    autopay_paused_at?: string | null;
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
  /** Legacy free-text day, no longer written or shown: superseded by billing_cycle (client, 2026-10-05). */
  payment_due_day: string | null;
  /** The customer's billing cycle, chosen right before signing: the 1st or the 15th of each month. */
  billing_cycle: BillingCycle | null;
  autopay_enabled: boolean;
  /** Set while staff have paused automatic charging on this lease. */
  autopay_paused_at: string | null;
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
  /** AutoPay (client, 2026-10-01) — set via /customer/lease-agreements/{id}/payment-methods. */
  stripe_bank_payment_method_id: string | null;
  stripe_card_payment_method_id: string | null;
  autopay_primary_method: "ach" | "card" | null;
  payment_methods_override_by?: { id: number; name: string } | null;
  payment_methods_override_at: string | null;
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
  /** Both prices (bank / card) of every charge, computed on the server. */
  pricing?: PricingSummary;
  /** What each billing cycle would look like if equipment were picked up today. Only on an unsigned lease's `show` response. */
  billing_preview?: Record<BillingCycle, BillingCyclePreview>;
  /** Only present on the guest signed-link endpoint (PublicContractController::show()) — whether step 1 (account creation) is already done. */
  customer_account_active?: boolean;
  /** Only present on the guest signed-link endpoint — `customer` isn't eager-loaded there. */
  customer_email?: string;
}
