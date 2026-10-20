"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import { money } from "@/components/applications/wizard/types";
import { ApiError } from "@/lib/api";
import { downloadPaymentsCsv, getSalesTaxReport, type SalesTaxReport } from "@/lib/payments";

const METHOD_LABEL: Record<string, string> = {
  ach: "Bank account",
  card: "Card",
  cash: "Cash",
  check: "Check",
  other: "Other",
  unknown: "Not recorded",
  split: "More than one method",
};

function isoDate(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, "0");

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * Sales tax and payments report (client, Joel, 2026-10-16: "as we collect sales tax, we will need the correct reports for
 * sales tax"; the signed plan's financial reporting): what was collected by month with the sales tax inside it, and a download
 * of every payment for the books. The wording at the bottom says exactly what is counted.
 */
export default function AdminReportsPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  // Mirrors User::hasAdminPermission() on the backend, like the Payments page.
  const canSee = isSuperAdmin || restrictions.length === 0 || restrictions.includes("payment_tracking");

  const [from, setFrom] = useState(() => `${new Date().getFullYear()}-01-01`);
  const [to, setTo] = useState(() => isoDate(new Date()));
  const [report, setReport] = useState<SalesTaxReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [downloading, setDownloading] = useState(false);

  useEffect(() => {
    if (!canSee || !from || !to || to < from) return;
    let cancelled = false;
    getSalesTaxReport(from, to)
      .then((data) => {
        if (!cancelled) {
          setReport(data);
          setError(null);
        }
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Could not load the report.");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [canSee, from, to]);

  if (!canSee) {
    return (
      <div className="space-y-3">
        <h1 className="text-2xl font-bold uppercase tracking-tight">Reports</h1>
        <p className="max-w-prose text-sm text-neutral-500">
          Your admin account is restricted and does not include payment tracking. Ask a super admin to add the Payment Tracking
          permission to your account.
        </p>
      </div>
    );
  }

  async function handleDownload() {
    setDownloading(true);
    setError(null);
    try {
      await downloadPaymentsCsv(from, to);
    } catch {
      setError("Could not download the report. Please try again.");
    } finally {
      setDownloading(false);
    }
  }

  const rangeIsValid = !!from && !!to && to >= from;
  const totals = report?.totals;

  return (
    <div className="space-y-6">
      <PageHeroHeader
        title="Reports"
        subtitle="Sales tax and payments collected, by month."
        action={
          <Link
            href="/admin/payments"
            className="font-heading self-start whitespace-nowrap rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
          >
            ← Payments
          </Link>
        }
      />

      <div className="flex flex-wrap items-end gap-3 rounded-xl border border-neutral-200 bg-white p-4">
        <div>
          <label htmlFor="report-from" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            From
          </label>
          <input
            id="report-from"
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="rounded-md border border-neutral-300 px-3 py-2 text-sm"
          />
        </div>
        <div>
          <label htmlFor="report-to" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
            To
          </label>
          <input
            id="report-to"
            type="date"
            value={to}
            min={from}
            onChange={(e) => setTo(e.target.value)}
            className="rounded-md border border-neutral-300 px-3 py-2 text-sm"
          />
        </div>
        <button
          onClick={handleDownload}
          disabled={downloading || !rangeIsValid}
          className="font-heading rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
        >
          {downloading ? "Preparing…" : "Download every payment (CSV)"}
        </button>
        {!rangeIsValid && <p className="text-xs text-red-600">The end date cannot be before the start date.</p>}
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}
      {loading && !report ? <p className="py-6 text-sm text-neutral-500">Loading the report…</p> : null}

      {report && totals && (
        <>
          <div className="grid grid-cols-1 divide-y divide-neutral-200 rounded-xl border border-neutral-200 bg-white sm:grid-cols-2 sm:divide-x sm:divide-y-0 lg:grid-cols-4">
            {[
              {
                label: "Sales tax collected",
                value: money(totals.sales_tax),
                note: `${totals.payments} payment${totals.payments === 1 ? "" : "s"} collected${totals.refunds > 0 ? `, ${totals.refunds} refund${totals.refunds === 1 ? "" : "s"} taken off` : ""}`,
              },
              { label: "Taxable sales", value: money(totals.taxable_sales), note: `Rent ${money(totals.rent)} and LDW ${money(totals.ldw)}, before tax` },
              { label: "Card fees", value: money(totals.card_fees), note: "Kept apart, not rent and not taxed" },
              {
                label: "Total collected",
                value: money(totals.total_collected),
                note: totals.refunded > 0 ? `Card fees included, ${money(totals.refunded)} refunded already taken off` : "Everything received, card fees included",
              },
            ].map((card) => (
              <div key={card.label} className="p-5">
                <p className="text-xs font-bold uppercase tracking-wide text-neutral-500">{card.label}</p>
                <p className="font-heading mt-1 text-3xl font-black text-neutral-900">{card.value}</p>
                <p className="mt-1 text-xs text-neutral-500">{card.note}</p>
              </div>
            ))}
          </div>

          <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
            <div className="mb-4">
              <SectionHeading title="By month" subtitle="By the day each payment was recorded as paid, with refunds taken off on the day the money went back." />
            </div>
            <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
              <table className="w-full min-w-[960px] text-left text-sm">
                <thead>
                  <tr className="font-heading border-b border-neutral-200 text-xs font-bold uppercase tracking-wide text-neutral-400">
                    <th className="pb-2 font-medium">Month</th>
                    <th className="pb-2 text-right font-medium">Payments</th>
                    <th className="pb-2 text-right font-medium">Refunded</th>
                    <th className="pb-2 text-right font-medium">Taxable sales</th>
                    <th className="pb-2 text-right font-medium">Of which LDW</th>
                    <th className="pb-2 text-right font-medium">Sales tax</th>
                    <th className="pb-2 text-right font-medium">Tracking fees</th>
                    <th className="pb-2 text-right font-medium">Deposits</th>
                    <th className="pb-2 text-right font-medium">Late fees</th>
                    <th className="pb-2 text-right font-medium">Extra payments</th>
                    <th className="pb-2 text-right font-medium">Card fees</th>
                    <th className="pb-2 text-right font-medium">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {report.months.length === 0 ? (
                    <tr>
                      <td colSpan={12} className="py-6 text-center text-sm text-neutral-400">
                        No payments were collected in this period.
                      </td>
                    </tr>
                  ) : (
                    report.months.map((m) => (
                      <tr key={m.month} className="border-b border-neutral-100 tabular-nums">
                        <td className="py-2.5 font-semibold text-neutral-900">{m.label}</td>
                        <td className="py-2.5 text-right text-neutral-600">{m.payments}</td>
                        <td className={`py-2.5 text-right ${m.refunded > 0 ? "font-medium text-red-600" : "text-neutral-400"}`}>{m.refunded > 0 ? money(m.refunded) : "—"}</td>
                        <td className="py-2.5 text-right text-neutral-700">{money(m.taxable_sales)}</td>
                        <td className="py-2.5 text-right text-neutral-500">{money(m.ldw)}</td>
                        <td className="py-2.5 text-right font-semibold text-neutral-900">{money(m.sales_tax)}</td>
                        <td className="py-2.5 text-right text-neutral-700">{money(m.tracking_fees)}</td>
                        <td className="py-2.5 text-right text-neutral-700">{money(m.deposits)}</td>
                        <td className="py-2.5 text-right text-neutral-700">{money(m.late_fees)}</td>
                        <td className="py-2.5 text-right text-neutral-700">{money(m.extra_payments)}</td>
                        <td className="py-2.5 text-right text-neutral-700">{money(m.card_fees)}</td>
                        <td className="py-2.5 text-right font-semibold text-neutral-900">{money(m.total_collected)}</td>
                      </tr>
                    ))
                  )}
                </tbody>
                {report.months.length > 0 && (
                  <tfoot>
                    <tr className="font-semibold tabular-nums text-neutral-900">
                      <td className="pt-3">Total</td>
                      <td className="pt-3 text-right">{totals.payments}</td>
                      <td className="pt-3 text-right">{totals.refunded > 0 ? money(totals.refunded) : "—"}</td>
                      <td className="pt-3 text-right">{money(totals.taxable_sales)}</td>
                      <td className="pt-3 text-right text-neutral-500">{money(totals.ldw)}</td>
                      <td className="pt-3 text-right">{money(totals.sales_tax)}</td>
                      <td className="pt-3 text-right">{money(totals.tracking_fees)}</td>
                      <td className="pt-3 text-right">{money(totals.deposits)}</td>
                      <td className="pt-3 text-right">{money(totals.late_fees)}</td>
                      <td className="pt-3 text-right">{money(totals.extra_payments)}</td>
                      <td className="pt-3 text-right">{money(totals.card_fees)}</td>
                      <td className="pt-3 text-right">{money(totals.total_collected)}</td>
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </div>

          <div className="rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
            <div className="mb-3">
              <SectionHeading title="By payment method" subtitle="Total collected, card fees included." />
            </div>
            {Object.keys(report.by_method).length === 0 ? (
              <p className="text-sm text-neutral-400">Nothing collected in this period.</p>
            ) : (
              <ul className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                {Object.entries(report.by_method).map(([method, amount]) => (
                  <li key={method} className="flex items-center justify-between rounded-md bg-neutral-50 px-3 py-2">
                    <span className="text-neutral-600">{METHOD_LABEL[method] ?? method}</span>
                    <span className="font-semibold tabular-nums text-neutral-900">{money(amount)}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div className="rounded-xl border border-neutral-200 bg-neutral-50 p-4 text-xs leading-relaxed text-neutral-600 sm:p-5">
            <p className="mb-1 font-bold uppercase tracking-wide text-neutral-500">What is counted</p>
            <ul className="list-disc space-y-1 pl-5">
              <li>Only money that was actually received, on the day it was recorded as paid. A bank payment counts when the bank clears it.</li>
              <li>
                Sales tax is the share of each monthly payment that is tax (rent plus LDW, taxed at the lease&rsquo;s own rate). A prorated payment
                carries a proportional share. Deposits, late fees, the tracking fee and extra payments carry no tax here.
              </li>
              <li>The tracking fee and first month is one charge: the tracking fee is shown apart and the first month carries its tax. The first monthly line that delivery records next to it is never counted a second time.</li>
              <li>
                A refund is taken off on the day the money went back, split the same way as the payment it refunds (its share of the sales tax
                included), so a month shows what was kept. A refund that is still on its way does not count until the bank confirms it. A
                chargeback that is lost counts the same way as a refund. The download lists each refund as a line of its own with negative amounts.
              </li>
              <li>Card fees are shown separately and are not taxed. Sales tax due when a customer exercises the early purchase option is not in this report.</li>
              <li>Please check the method with your accountant before filing: this report shows what the portal recorded.</li>
            </ul>
          </div>
        </>
      )}
    </div>
  );
}
