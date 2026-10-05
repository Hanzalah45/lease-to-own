import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type { PricingSummary } from "@/types/lease-agreement";
import type { Application } from "@/types/application";

/** Everything the price calculator shows, computed by the server's real lease pricing code. */
export interface PriceQuote {
  cash_price: number;
  term_months: number;
  tax_rate: number;
  ldw_selected: boolean;
  monthly_rental: number;
  ldw_amount: number;
  sales_tax: number;
  total_monthly_payment: number;
  security_deposit: number;
  tracking_device_fee: number;
  total_due_today: number;
  total_rental_purchase_price: number;
  epo_today: number;
  epo_schedule: { month: number; value: number }[];
  /** Both prices (bank / card) of each charge. */
  pricing: PricingSummary;
}

export interface QuoteInput {
  cashPrice: string;
  taxRate: string;
  termMonths: string;
  ldw: "yes" | "no";
}

/** Prices a mower without a customer or an application: nothing is saved. */
export async function quotePrice(input: QuoteInput): Promise<PriceQuote> {
  const data = await apiFetch<{ data: PriceQuote }>("/admin/pricing/quote", {
    method: "POST",
    token: getToken(),
    body: {
      cash_price: input.cashPrice,
      tax_rate: input.taxRate || 0,
      term_months: Number(input.termMonths),
      ldw: input.ldw,
    },
  });
  return data.data;
}

export interface ChangeMowerInput extends QuoteInput {
  make: string;
  model: string;
  serial: string;
  condition: "new" | "used";
  year: string;
  description: string;
  /** Confirms that a signed contract may be cancelled so the customer signs the new price. */
  voidSignedContract: boolean;
}

/** Swaps the mower on an existing application and re-prices its lease. */
export async function changeMower(
  applicationId: number | string,
  input: ChangeMowerInput,
): Promise<{ application: Application; contractVoided: boolean }> {
  const data = await apiFetch<{ data: Application; meta: { contract_voided: boolean } }>(
    `/admin/applications/${applicationId}/change-equipment`,
    {
      method: "POST",
      token: getToken(),
      body: {
        make: input.make,
        model: input.model,
        serial: input.serial,
        condition: input.condition,
        year: input.year,
        description: input.description || null,
        cash_price: input.cashPrice,
        tax_rate: input.taxRate || 0,
        term_months: Number(input.termMonths),
        ldw: input.ldw,
        void_signed_contract: input.voidSignedContract,
      },
    },
  );
  return { application: data.data, contractVoided: data.meta.contract_voided };
}
