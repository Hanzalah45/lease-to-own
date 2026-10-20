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
  /** Which wording of the agreement this signature was given on (1 = the original wording). */
  terms_version?: number;
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

/** Money that went back to the customer for one payment, in full or in part (client, Joel, 2026-10-19). */
export interface PaymentRefund {
  id: number;
  payment_id: number;
  /** Includes the card fee when the whole payment is refunded. */
  amount: string;
  reason: string;
  note: string | null;
  /** portal: made from here through Stripe. manual: given back outside the portal and recorded. stripe: made in the Stripe dashboard. dispute: a chargeback that was lost. */
  source: "portal" | "manual" | "stripe" | "dispute";
  /** pending: on its way (a bank refund), or Stripe gave no answer yet. */
  status: "pending" | "succeeded" | "failed";
  stripe_refund_id: string | null;
  failure_message: string | null;
  refunded_at: string | null;
  created_at: string;
  refunded_by?: { id: number; name: string } | null;
}

/** A chargeback: the customer disputed a payment with their bank. */
export interface PaymentDispute {
  id: number;
  payment_id: number;
  amount: string;
  reason: string | null;
  /** Stripe's own words: needs_response, under_review, won, lost, warning_closed. */
  status: string;
  evidence_due_by: string | null;
  opened_at: string;
  closed_at: string | null;
}

/** One piece of a payment that was paid in more than one (client, Joel, 2026-10-19 checklist, section 7). */
export interface PaymentPart {
  id: number;
  payment_id?: number;
  method: "ach" | "card" | "cash" | "check" | "other";
  /** The bank price of this piece; the card fee is on top. */
  amount: string;
  card_fee_amount: string;
  /** pending: a bank debit on its way (or a charge nobody has an answer for). refunded: taken back whole from a payment that was still open. */
  status: "pending" | "paid" | "failed" | "refunded";
  failure_message?: string | null;
  paid_date: string | null;
  created_at?: string;
  started_by?: "staff" | "customer" | "system";
  /** Staff views only. */
  recorded_by?: { id: number; name: string } | null;
  refunded_total?: number;
  refundable_amount?: number;
}

/** What staff wrote about one payment. Never shown to customers. */
export interface PaymentNote {
  id: number;
  payment_id: number;
  kind: "general" | "failed" | "refund" | "chargeback";
  body: string;
  created_at: string;
  author?: { id: number; name: string } | null;
}

/** One line of the payment audit trail: who did what to which payment, when, with what method, from where. */
export interface PaymentAuditEntry {
  id: number;
  actor_type: "staff" | "customer" | "system";
  actor_name: string | null;
  /** A family and an action, like refund.completed or payment.paid. */
  action: string;
  lease_agreement_id: number | null;
  payment_id: number | null;
  method: string | null;
  amount: string | null;
  summary: string;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string;
  payment?: { id: number; lease_agreement_id: number; type: Payment["type"]; due_date: string } | null;
  lease_agreement?: { id: number; customer_id: number; application_id: number; customer?: { id: number; name: string } | null } | null;
}

export interface Payment {
  id: number;
  lease_agreement_id: number;
  type: "rental" | "late_fee" | "deposit" | "pickup_balance" | "extra_payment";
  late_fee_for_payment_id: number | null;
  /** The BANK price. The total actually charged is amount + card_fee_amount. */
  amount: string;
  /** The extra card fee charged on top (0 for a bank payment). */
  card_fee_amount: string;
  due_date: string;
  paid_date: string | null;
  /** split: paid in pieces that used different methods. */
  method: "ach" | "card" | "cash" | "check" | "other" | "split" | null;
  status: "pending" | "paid" | "failed" | "refunded";
  /** Why a charge failed, in the processor's words (client, Joel, 2026-10-13). Rental payments keep theirs per attempt. */
  failure_message?: string | null;
  /** Set once a charge was started with Stripe: a pending payment that has one is processing, not just scheduled. */
  stripe_payment_intent_id?: string | null;
  recorded_by?: { id: number; name: string } | null;
  /** Automatic-charge history for a rental payment, oldest first (admin views only). */
  attempts?: PaymentAttempt[];
  /** Staff views only: refunds of this payment (oldest first), what they add up to and what can still be refunded. */
  refunds?: PaymentRefund[];
  refunded_total?: number;
  refundable_amount?: number;
  /** Staff views only: chargebacks reported against this payment. */
  disputes?: PaymentDispute[];
  /** Staff views only: what staff wrote about this payment, oldest first. */
  notes?: PaymentNote[];
  /** The pieces this payment was paid in, when it was not paid in one go, oldest first. */
  parts?: PaymentPart[];
  /** What is still to be paid (the price less the pieces that went through), and what a new charge may be for (also less pieces still on their way). */
  remaining_amount?: number;
  chargeable_amount?: number;
  /** Staff views only: what this payment is applied to. */
  applied_to?: "rent" | "fees" | "epo" | "deposit" | "tracking_fee_and_first_month" | "other";
  /** Customer portal only: whether this due monthly payment can be paid from the portal now (see CustomerPaymentCharger::options). Null when it is not due or not a monthly payment. */
  pay?: PayOptions | null;
  lease_agreement?: {
    id: number;
    application_id: number;
    autopay_enabled: boolean;
    autopay_paused_at?: string | null;
    customer?: { id: number; name: string; email: string };
  };
}

export interface PayOptions {
  payable: boolean;
  /** Why it cannot be paid right now, in words for the customer. */
  blocker: string | null;
  /** The saved methods that can be used. */
  available_methods: Array<"bank" | "card">;
  /** Prices for what can still be charged (everything that is left of the payment). */
  prices: PriceOption;
  /** Paying in parts: what is left, the smallest part a customer can pay on their own, the card fee rate in basis points, and whether a part can be paid now. */
  remaining_amount?: number;
  chargeable_amount?: number;
  min_piece?: number;
  card_fee_bps?: number;
  can_pay_in_parts?: boolean;
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
  /** Set when staff ended the lease (equipment returned, early purchase completed, or another reason): nothing more is billed. */
  ended_at?: string | null;
  ended_reason?: "returned" | "purchased" | "other" | null;
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
  /** The latest time the customer turned AutoPay on with the tick box, if they did. */
  autopay_agreement?: { id: number; agreed_at: string; via: "portal" | "signed_link"; wording_version: number } | null;
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
  /** Set when the customer already picked up (re-signing the new contract): the examples are built from this real date instead of "today". */
  billing_preview_pickup_date?: string | null;
  /** Only present on the guest signed-link endpoint (PublicContractController::show()) — whether step 1 (account creation) is already done. */
  customer_account_active?: boolean;
  /** Only present on the guest signed-link endpoint — `customer` isn't eager-loaded there. */
  customer_email?: string;
}
