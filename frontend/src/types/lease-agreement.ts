export type OwnershipStatus = "leasing" | "owned";

export interface LeaseAgreement {
  id: number;
  application_id: number;
  customer_id: number;
  equipment_unit_id: number | null;
  start_date: string;
  renewal_date: string;
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
}
