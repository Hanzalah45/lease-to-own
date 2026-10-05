import { EpoChart } from "@/components/applications/wizard/EpoChart";
import { money } from "@/components/applications/wizard/types";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import type { PriceQuote } from "@/lib/pricing";

/**
 * Every figure a customer is quoted, from the server's real pricing code:
 * the monthly build-up, what is due at pickup at the bank price and the card
 * price (card payments cost more, see CardPricing on the server), and the
 * early-purchase payoff chart.
 */
export function PriceBreakdown({ quote }: { quote: PriceQuote }) {
  const { pricing } = quote;

  const methodRows: { label: string; bank: number; card: number; strong?: boolean }[] = [
    { label: "Total monthly payment", bank: pricing.monthly.bank, card: pricing.monthly.card },
    { label: "Security deposit", bank: pricing.deposit.bank, card: pricing.deposit.card },
    { label: "Tracking fee + first month", bank: pricing.pickup_balance.bank, card: pricing.pickup_balance.card },
    { label: "Total due at pickup", bank: pricing.full.bank, card: pricing.full.card, strong: true },
  ];

  return (
    <div className="space-y-6">
      <div className="rounded-xl border border-neutral-200 bg-white p-6">
        <SectionHeading title="Monthly payment" />
        <dl className="mt-4 space-y-2.5 text-sm">
          <Row label="Monthly rental" value={money(quote.monthly_rental)} />
          <Row
            label={quote.ldw_selected ? "LDW (Loss Damage Waiver)" : "LDW declined"}
            value={`${money(quote.ldw_amount)} / mo`}
          />
          <Row label="Sales tax" value={money(quote.sales_tax)} />
          <Row label="Total monthly payment" value={money(quote.total_monthly_payment)} strong />
        </dl>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-6">
        <SectionHeading
          title="What the customer pays"
          subtitle={`Paying by card costs ${pricing.card_fee_percent}% more than paying from a bank account (ACH). The extra is a processing cost and does not count toward ownership.`}
        />
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[22rem] text-sm">
            <thead>
              <tr className="text-left text-xs uppercase tracking-wide text-neutral-400">
                <th className="pb-2 font-semibold"> </th>
                <th className="pb-2 text-right font-semibold">Bank (ACH)</th>
                <th className="pb-2 text-right font-semibold">Card</th>
              </tr>
            </thead>
            <tbody>
              {methodRows.map((r) => (
                <tr key={r.label} className={`border-t border-neutral-100 ${r.strong ? "font-bold text-neutral-900" : "text-neutral-700"}`}>
                  <td className="py-2.5 pr-4">{r.label}</td>
                  <td className="py-2.5 text-right tabular-nums">{money(r.bank)}</td>
                  <td className="py-2.5 text-right tabular-nums">{money(r.card)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-6">
        <SectionHeading
          title="Early purchase option: payoff preview"
          subtitle={`Excludes tax. Today the payoff would be ${money(quote.epo_today)}.`}
        />
        <div className="mt-6">
          <EpoChart schedule={quote.epo_schedule} />
        </div>
      </div>
    </div>
  );
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className={`flex items-center justify-between gap-4 ${strong ? "border-t border-neutral-100 pt-2.5 font-bold text-neutral-900" : "text-neutral-700"}`}>
      <dt>{label}</dt>
      <dd className="tabular-nums">{value}</dd>
    </div>
  );
}
