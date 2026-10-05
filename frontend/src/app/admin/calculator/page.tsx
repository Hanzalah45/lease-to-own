"use client";

import { useEffect, useMemo, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { Field, RadioGroup, TextInput } from "@/components/applications/wizard/fields";
import { SidebarCard } from "@/components/applications/wizard/SidebarCard";
import { TERM_MONTH_OPTIONS, money } from "@/components/applications/wizard/types";
import { PriceBreakdown } from "@/components/pricing/PriceBreakdown";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { ApiError } from "@/lib/api";
import { quotePrice, type PriceQuote, type QuoteInput } from "@/lib/pricing";
import { CASH_PRICE_MAX, validateMoney, validatePercent } from "@/lib/validation";

/** Waits for the user to stop typing before asking the server for a quote. */
const QUOTE_DELAY_MS = 300;

interface QuoteResult {
  /** Which inputs this result belongs to, so a slow answer for old inputs is never shown. */
  key: string;
  quote?: PriceQuote;
  error?: string;
}

export default function AdminPriceCalculatorPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  // Mirrors the backend: the quote endpoint sits behind application_review.
  const canUse = isSuperAdmin || restrictions.length === 0 || restrictions.includes("application_review");

  const [cashPrice, setCashPrice] = useState("");
  const [taxRate, setTaxRate] = useState("8.25");
  const [termMonths, setTermMonths] = useState("36");
  const [ldw, setLdw] = useState<"yes" | "no">("yes");
  const [result, setResult] = useState<QuoteResult | null>(null);

  const cashError = cashPrice.trim() ? validateMoney(cashPrice, "Cash price", { aboveZero: true, max: CASH_PRICE_MAX }) : undefined;
  const taxError = validatePercent(taxRate, "Sales tax rate");
  const ready = cashPrice.trim() !== "" && !cashError && !taxError;

  const input = useMemo<QuoteInput>(() => ({ cashPrice, taxRate, termMonths, ldw }), [cashPrice, taxRate, termMonths, ldw]);
  const inputKey = JSON.stringify(input);

  useEffect(() => {
    if (!ready || !canUse) return;
    let cancelled = false;
    const timer = setTimeout(async () => {
      try {
        const quote = await quotePrice(input);
        if (!cancelled) setResult({ key: inputKey, quote });
      } catch (err) {
        if (!cancelled) setResult({ key: inputKey, error: err instanceof ApiError ? err.message : "Could not calculate this price. Please try again." });
      }
    }, QUOTE_DELAY_MS);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [ready, canUse, input, inputKey]);

  if (!canUse) {
    return (
      <div className="space-y-3">
        <h1 className="text-2xl font-bold uppercase tracking-tight">Price calculator</h1>
        <p className="max-w-prose text-sm text-neutral-500">
          Your admin account is restricted and does not include application review. Ask a super admin to add the
          Application Review permission to your account.
        </p>
      </div>
    );
  }

  const current = ready && result?.key === inputKey ? result : null;
  const quote = current?.quote;

  return (
    <div className="space-y-6">
      <PageHeroHeader
        title="Price calculator"
        subtitle="Check a mower's price with a customer before entering it. Nothing is saved and no customer details are needed."
      />

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_20rem]">
        <div className="space-y-6">
          <div className="rounded-xl border border-neutral-200 bg-white p-6">
            <SectionHeading title="Mower pricing" />
            <div className="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
              <Field label="Cash Price / Retail" required error={cashError}>
                <TextInput value={cashPrice} onChange={setCashPrice} placeholder="4899" type="number" hasError={!!cashError} />
              </Field>
              <Field label="Sales Tax %" required error={taxError}>
                <TextInput value={taxRate} onChange={setTaxRate} placeholder="8.25" type="number" hasError={!!taxError} />
              </Field>
              <Field label="Lease Months to Ownership">
                <RadioGroup
                  value={termMonths}
                  onChange={setTermMonths}
                  options={TERM_MONTH_OPTIONS.map((m) => ({ value: String(m), label: `${m} mo` }))}
                />
              </Field>
              <Field label="LDW (Loss Damage Waiver)">
                <RadioGroup
                  value={ldw}
                  onChange={(v) => setLdw(v as "yes" | "no")}
                  options={[
                    { value: "yes", label: "Yes" },
                    { value: "no", label: "No" },
                  ]}
                />
              </Field>
            </div>
            <p className="mt-5 text-xs text-neutral-400">
              Monthly rental is cash price ÷ 10.0, 16.0, or 19.8 for 12, 24 or 36 months. Taking LDW adds 0.75% of the
              cash price per month and sets the deposit to 7% of the cash price. Declining it adds nothing monthly and
              sets the deposit to 3× the monthly payment. The $150 tracking device fee is separate. These are the same
              numbers the New Application form and the contract use.
            </p>
          </div>

          {current?.error && <p className="text-sm text-red-600">{current.error}</p>}
          {!ready && (
            <div className="rounded-xl border border-dashed border-neutral-300 bg-white p-8 text-center text-sm text-neutral-500">
              Enter a cash price to see the monthly payment, what is due at pickup, and the payoff schedule.
            </div>
          )}
          {ready && !quote && !current?.error && <p className="text-sm text-neutral-500">Calculating…</p>}
          {quote && <PriceBreakdown quote={quote} />}
        </div>

        <div className="space-y-4">
          <SidebarCard
            title="Quote summary"
            rows={[
              { label: "Cash price", value: quote ? money(quote.cash_price) : "—" },
              { label: "Term", value: `${termMonths} mo` },
              { label: "Monthly (bank)", value: quote ? money(quote.pricing.monthly.bank) : "—" },
              { label: "Monthly (card)", value: quote ? money(quote.pricing.monthly.card) : "—" },
              { label: "Due at pickup (bank)", value: quote ? money(quote.pricing.full.bank) : "—", highlight: true },
              { label: "Due at pickup (card)", value: quote ? money(quote.pricing.full.card) : "—", highlight: true },
              { label: "Total rental price", value: quote ? money(quote.total_rental_purchase_price) : "—" },
            ]}
          />
        </div>
      </div>
    </div>
  );
}
