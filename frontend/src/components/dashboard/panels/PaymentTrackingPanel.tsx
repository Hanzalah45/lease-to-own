"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { DataTable, type DataTableColumn } from "@/components/dashboard/DataTable";
import { EarningsChart, type EarningsPoint } from "@/components/dashboard/EarningsChart";
import { FundedVolumeChart, type WeekPoint } from "@/components/dashboard/FundedVolumeChart";
import { StatCard } from "@/components/dashboard/StatCard";
import { StatusTag } from "@/components/dashboard/StatusTag";
import { Modal } from "@/components/ui/Modal";
import { money } from "@/components/applications/wizard/types";
import { RefundModal } from "@/components/applications/detail/RefundModal";
import { AlertCircleIcon, ArrowUpRightIcon, CheckCircleIcon, ClockIcon, CreditCardIcon } from "@/components/icons";
import { formatDateOnly, isBeforeToday, isToday } from "@/lib/dates";
import { firstMonthMirrors } from "@/lib/application-progress";
import { listPayments, markPaymentStatus, retryAutopayCharge } from "@/lib/payments";
import { ApiError } from "@/lib/api";
import type { Payment } from "@/types/lease-agreement";

const MONTH_LABELS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

/** How many payments one page of the main list shows. */
const PAGE_SIZE = 15;
const RECENT_PAID_COUNT = 8;
const METHOD_LABEL: Record<string, string> = { ach: "Bank account", card: "Card", cash: "Cash", check: "Check", other: "Other", split: "More than one" };

/** How staff say a payment they mark paid was received (client, Joel, 2026-10-16: cash or check in the office). */
const PAID_BY_OPTIONS: Array<{ value: "cash" | "check" | "ach" | "card" | "other"; label: string }> = [
  { value: "cash", label: "Cash" },
  { value: "check", label: "Check" },
  { value: "ach", label: "Bank transfer" },
  { value: "card", label: "Card" },
  { value: "other", label: "Other" },
];

function dueKey(payment: Payment): string {
  return payment.due_date.slice(0, 10);
}

/** A debit or card charge has been started with the processor and has not settled yet. */
function isProcessing(payment: Payment): boolean {
  return payment.status === "pending" && (!!payment.stripe_payment_intent_id || !!payment.attempts?.some((a) => a.status === "processing"));
}

/** Why the last automatic attempt failed, e.g. "Bank account failed: account_closed". */
function lastFailure(payment: Payment): string | null {
  const failed = [...(payment.attempts ?? [])].reverse().find((a) => a.status === "failed");
  if (!failed) return null;
  const reason = failed.failure_message ?? failed.failure_code;
  return `${failed.method === "bank" ? "Bank account" : "Card"} failed${reason ? `: ${reason}` : ""}`;
}

/** What the "Payments to track" list can be narrowed to (client, Joel, 2026-10-19: see what is processing apart from what is set to be paid). */
type TrackingFilter = "all" | "attention" | "processing" | "autopay" | "upcoming";

const FILTERS: Array<{ value: TrackingFilter; label: string }> = [
  { value: "all", label: "All" },
  { value: "attention", label: "Failed or overdue" },
  { value: "processing", label: "Processing" },
  { value: "autopay", label: "AutoPay set" },
  { value: "upcoming", label: "Upcoming, no AutoPay" },
];

/** Which filter a row of the list belongs to. */
function trackingGroup(payment: Payment): Exclude<TrackingFilter, "all"> {
  if (payment.status === "failed" || (payment.status === "pending" && isBeforeToday(payment.due_date) && !isProcessing(payment))) return "attention";
  if (isProcessing(payment)) return "processing";
  if (payment.lease_agreement?.autopay_enabled && !payment.lease_agreement.autopay_paused_at) return "autopay";

  return "upcoming";
}

function pctChange(current: number, prior: number): string {
  if (prior === 0) return current > 0 ? "New this month" : "No change vs last month";
  const change = ((current - prior) / prior) * 100;
  return `${change >= 0 ? "+" : ""}${change.toFixed(1)}% vs last month`;
}

export function PaymentTrackingPanel() {
  const [payments, setPayments] = useState<Payment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [confirming, setConfirming] = useState<{ payment: Payment; status: "paid" | "failed" } | null>(null);
  const [confirmError, setConfirmError] = useState<string | null>(null);
  const [confirmBusy, setConfirmBusy] = useState(false);
  const [retryingId, setRetryingId] = useState<number | null>(null);
  const [retryError, setRetryError] = useState<string | null>(null);
  const [refunding, setRefunding] = useState<Payment | null>(null);
  const [refundNotice, setRefundNotice] = useState<string | null>(null);
  const [filter, setFilter] = useState<TrackingFilter>("all");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  // How a payment staff mark paid was received.
  const [paidBy, setPaidBy] = useState<(typeof PAID_BY_OPTIONS)[number]["value"]>("cash");

  function load() {
    return listPayments()
      .then(setPayments)
      .catch((err) =>
        setError(
          err instanceof ApiError && err.status === 403
            ? "Your admin account does not include payment tracking."
            : "Could not load payments.",
        ),
      )
      .finally(() => setLoading(false));
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function confirmMark() {
    if (!confirming) return;
    setConfirmBusy(true);
    setConfirmError(null);
    try {
      await markPaymentStatus(confirming.payment.id, confirming.status, confirming.status === "paid" ? paidBy : undefined);
      setConfirming(null);
      await load();
    } catch {
      setConfirmError("Could not update that payment. Please try again.");
    } finally {
      setConfirmBusy(false);
    }
  }

  // Automatic charging does not retry a payment that failed on every method,
  // so staff decide when to try again (a fresh round, primary method first).
  async function retryCharge(payment: Payment) {
    setRetryError(null);
    setRetryingId(payment.id);
    try {
      await retryAutopayCharge(payment.id);
      await load();
    } catch (err) {
      setRetryError(err instanceof ApiError ? err.message : "Could not retry that charge. Please try again.");
    } finally {
      setRetryingId(null);
    }
  }

  if (loading) return <p className="py-6 text-sm text-neutral-500">Loading payments…</p>;
  if (error) return <p className="py-6 text-sm text-neutral-500">{error}</p>;

  const now = new Date();
  const startOfThisMonth = new Date(now.getFullYear(), now.getMonth(), 1);
  const startOfLastMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);

  // Revenue KPIs below (collected this/last month, avg monthly payment, the
  // earnings chart) are recurring-rent figures — a one-time deposit charge
  // (real money now, client, 2026-10-01) would otherwise show up as an
  // outlier "monthly payment" and distort the trend the moment it lands.
  // overduePayments and the DataTable below stay type-agnostic on purpose: a
  // stuck/declined deposit should still surface there.
  const recurring = payments.filter((p) => p.type === "rental");
  // Marking a lease delivered records its first month as paid without any money moving (the money is in the tracking fee and
  // first month charge): until that charge has cleared, the record is not collected money.
  const firstMonths = firstMonthMirrors(payments);
  const firstMonthIds = new Set(firstMonths.map((f) => f.mirror.id));
  const uncollectedFirstMonths = new Set(firstMonths.filter((f) => f.charge.status !== "paid").map((f) => f.mirror.id));
  const paid = recurring.filter((p) => p.status === "paid" && p.paid_date && !uncollectedFirstMonths.has(p.id));
  const collectedThisMonth = paid
    .filter((p) => new Date(p.paid_date!) >= startOfThisMonth)
    .reduce((sum, p) => sum + Number(p.amount), 0);
  const collectedLastMonth = paid
    .filter((p) => new Date(p.paid_date!) >= startOfLastMonth && new Date(p.paid_date!) < startOfThisMonth)
    .reduce((sum, p) => sum + Number(p.amount), 0);

  // A debit that is already on its way is not overdue, it just has not settled.
  const overduePayments = payments.filter((p) => p.type !== "extra_payment" && p.status === "pending" && isBeforeToday(p.due_date) && !isProcessing(p));

  // Only leases that still have something to collect count: one with nothing but paid rows left (a cancelled lease, a finished
  // one) is neither enrolled nor missing from AutoPay.
  const leaseIds = new Set(payments.filter((p) => p.status === "pending" || p.status === "failed").map((p) => p.lease_agreement_id));
  const autopayLeaseIds = new Set(
    payments.filter((p) => leaseIds.has(p.lease_agreement_id) && p.lease_agreement?.autopay_enabled).map((p) => p.lease_agreement_id),
  );
  const autopayPct = leaseIds.size === 0 ? 0 : Math.round((autopayLeaseIds.size / leaseIds.size) * 100);

  // Only payments that were actually collected: the schedule rows still to come would otherwise swamp this average.
  const avgMonthlyPayment = paid.length === 0 ? 0 : paid.reduce((sum, p) => sum + Number(p.amount), 0) / paid.length;

  const earningsData: EarningsPoint[] = MONTH_LABELS.map((label, i) => {
    const thisYear = paid
      .filter((p) => {
        const d = new Date(p.paid_date!);
        return d.getMonth() === i && d.getFullYear() === now.getFullYear();
      })
      .reduce((sum, p) => sum + Number(p.amount), 0);
    const lastYear = paid
      .filter((p) => {
        const d = new Date(p.paid_date!);
        return d.getMonth() === i && d.getFullYear() === now.getFullYear() - 1;
      })
      .reduce((sum, p) => sum + Number(p.amount), 0);
    return { month: label, thisYear, lastYear };
  });
  const totalEarnings = paid.reduce((sum, p) => sum + Number(p.amount), 0);
  const monthsWithData = earningsData.filter((p) => p.thisYear > 0).length || 1;
  const bestMonth = earningsData.reduce((best, p) => (p.thisYear > best.thisYear ? p : best), earningsData[0]);

  const collectionsData: WeekPoint[] = Array.from({ length: 8 }, (_, i) => {
    const weeksAgo = 7 - i;
    const weekStart = new Date(now);
    weekStart.setDate(weekStart.getDate() - weeksAgo * 7 - now.getDay());
    const weekEnd = new Date(weekStart);
    weekEnd.setDate(weekEnd.getDate() + 7);
    const units = paid.filter((p) => {
      const d = new Date(p.paid_date!);
      return d >= weekStart && d < weekEnd;
    }).length;
    return { label: `Wk ${i + 1}`, units };
  });

  // What staff work from, most urgent first (client, Joel, 2026-10-16: it listed
  // the farthest due dates first): payments that failed, then overdue ones (oldest
  // first), then charges still settling, then only the NEXT upcoming monthly
  // payment of each lease, soonest first. The schedule has one row per month, so
  // listing all of them would bury the few that matter.
  const byDue = (a: Payment, b: Payment) => dueKey(a).localeCompare(dueKey(b)) || a.id - b.id;
  // An extra payment (toward the early purchase price) is optional: a declined one is nothing to follow up, so only one that
  // is still clearing shows here.
  const failedRows = payments.filter((p) => p.status === "failed" && p.type !== "extra_payment").sort(byDue);
  const overdueRows = [...overduePayments].sort(byDue);
  const processingRows = payments.filter((p) => isProcessing(p) && !overduePayments.includes(p)).sort(byDue);
  const nextMonthly = new Map<number, Payment>();
  const otherUpcoming: Payment[] = [];
  for (const p of [...payments].sort(byDue)) {
    if (p.type === "extra_payment" || p.status !== "pending" || isBeforeToday(p.due_date) || isProcessing(p)) continue;
    if (p.type !== "rental") otherUpcoming.push(p);
    else if (!nextMonthly.has(p.lease_agreement_id)) nextMonthly.set(p.lease_agreement_id, p);
  }
  const upcomingRows = [...nextMonthly.values(), ...otherUpcoming].sort(byDue);
  const trackingRows = [...failedRows, ...overdueRows, ...processingRows, ...upcomingRows];
  const groupCounts: Record<TrackingFilter, number> = { all: trackingRows.length, attention: 0, processing: 0, autopay: 0, upcoming: 0 };
  for (const row of trackingRows) groupCounts[trackingGroup(row)]++;
  const needle = search.trim().toLowerCase();
  const shownRows = trackingRows.filter(
    (row) =>
      (filter === "all" || trackingGroup(row) === filter) &&
      (needle === "" || (row.lease_agreement?.customer?.name ?? "").toLowerCase().includes(needle)),
  );
  const pageCount = Math.max(1, Math.ceil(shownRows.length / PAGE_SIZE));
  const currentPage = Math.min(page, pageCount);
  const pageRows = shownRows.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

  const recentlyPaid = payments
    // The first month's record row is left out: its money is listed once, as the tracking fee and first month payment.
    .filter((p) => p.status === "paid" && p.paid_date && !firstMonthIds.has(p.id))
    .sort((a, b) => b.paid_date!.localeCompare(a.paid_date!) || b.id - a.id)
    .slice(0, RECENT_PAID_COUNT);

  const columns: DataTableColumn<Payment>[] = [
    {
      key: "status",
      header: "Status",
      render: (r) => {
        if (r.status === "failed") return <StatusTag color="#DC2626" label="Charge failed" />;
        if (isProcessing(r)) return <StatusTag color="#2563EB" label="Payment processing" />;
        if (r.status === "pending" && isBeforeToday(r.due_date)) return <StatusTag color="#DC2626" label="Overdue" />;
        if (r.status === "pending" && isToday(r.due_date)) return <StatusTag color="#D97706" label="Due today" />;
        if (r.lease_agreement?.autopay_paused_at) return <StatusTag color="#D97706" label="Autopay paused" />;
        if (r.lease_agreement?.autopay_enabled) return <StatusTag color="#16A34A" label="Autopay set" />;
        return <StatusTag color="#7C3AED" label="Upcoming" />;
      },
    },
    {
      key: "customer",
      header: "Customer",
      render: (r) => (
        <Link href={`/admin/applications/${r.lease_agreement?.application_id}`} className="font-medium text-neutral-900 hover:underline">
          {r.lease_agreement?.customer?.name ?? "—"}
        </Link>
      ),
    },
    {
      key: "amount",
      header: "Amount",
      render: (r) => (
        <span className="text-neutral-700">
          {money(Number(r.amount))}
          {Number(r.card_fee_amount) > 0 && (
            <span className="ml-1.5 text-xs text-neutral-400">+ {money(Number(r.card_fee_amount))} card fee</span>
          )}
          {r.type === "late_fee" && (
            <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700">
              Late fee
            </span>
          )}
          {r.type === "deposit" && (
            <span className="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-blue-700">
              Deposit
            </span>
          )}
          {r.type === "pickup_balance" && (
            <span className="ml-2 rounded-full bg-purple-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-purple-700">
              Pickup balance
            </span>
          )}
          {r.type === "extra_payment" && (
            <span className="ml-2 rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-green-700">
              Extra payment
            </span>
          )}
          {r.status === "failed" && lastFailure(r) && (
            <span className="mt-0.5 block text-xs text-red-600">{lastFailure(r)}</span>
          )}
        </span>
      ),
    },
    { key: "due", header: "Due", render: (r) => <span className="text-neutral-500">{formatDateOnly(r.due_date)}</span> },
    {
      key: "action",
      header: "",
      render: (r) => (
        <div className="flex items-center justify-end gap-3">
          {r.type === "rental" && r.status === "failed" && r.lease_agreement?.autopay_enabled && !r.lease_agreement.autopay_paused_at && (
            <button
              onClick={() => retryCharge(r)}
              disabled={retryingId === r.id}
              className="text-sm font-semibold text-blue-700 hover:underline disabled:opacity-50"
            >
              {retryingId === r.id ? "Charging…" : "Retry charge"}
            </button>
          )}
          <button
            onClick={() => {
              // Keeps how it was paid when that is already known (a bank debit marked paid by hand stays a bank payment).
              setPaidBy(r.method && r.method !== "split" ? r.method : "cash");
              setConfirming({ payment: r, status: "paid" });
            }}
            className="text-sm font-semibold text-green-700 hover:underline"
          >
            Mark Paid
          </button>
          <button
            onClick={() => setConfirming({ payment: r, status: "failed" })}
            className="text-sm font-semibold text-red-600 hover:underline"
          >
            Mark Failed
          </button>
        </div>
      ),
    },
  ];

  const column = (key: string) => columns.find((c) => c.key === key)!;
  const recentColumns: DataTableColumn<Payment>[] = [
    column("customer"),
    column("amount"),
    {
      key: "method",
      header: "Paid with",
      render: (r) => <span className="text-neutral-500">{r.method ? METHOD_LABEL[r.method] : "—"}</span>,
    },
    { key: "paid", header: "Paid on", render: (r) => <span className="text-neutral-500">{formatDateOnly(r.paid_date)}</span> },
    {
      key: "refund",
      header: "",
      render: (r) => (
        <div className="flex items-center justify-end gap-3">
          {(r.refunded_total ?? 0) > 0 && <span className="text-xs text-amber-700">{money(r.refunded_total ?? 0)} refunded</span>}
          {(r.refundable_amount ?? 0) > 0 && (
            <button
              onClick={() => {
                setRefundNotice(null);
                setRefunding(r);
              }}
              className="text-sm font-semibold text-red-600 hover:underline"
            >
              Refund
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 divide-y divide-neutral-200 rounded-xl border border-neutral-200 bg-white sm:grid-cols-2 sm:divide-x sm:divide-y-0 lg:grid-cols-4">
        <StatCard
          label="Collected this month"
          value={money(collectedThisMonth)}
          note={pctChange(collectedThisMonth, collectedLastMonth)}
          noteTone={collectedThisMonth >= collectedLastMonth ? "positive" : "warning"}
          icon={CreditCardIcon}
          iconBg="#DCFCE7"
          iconColor="#16A34A"
          noteIcon={ArrowUpRightIcon}
        />
        <StatCard
          label="Overdue"
          value={String(overduePayments.length)}
          note={overduePayments.length > 0 ? "Requires attention" : "All caught up"}
          noteTone={overduePayments.length > 0 ? "warning" : "positive"}
          icon={AlertCircleIcon}
          iconBg="#FEF3C7"
          iconColor="#D97706"
          noteIcon={ClockIcon}
        />
        <StatCard
          label="Autopay enrolled"
          value={`${autopayPct}%`}
          note={`${autopayLeaseIds.size} of ${leaseIds.size} leases`}
          noteTone="neutral"
          icon={CheckCircleIcon}
          iconBg="#FCE7EE"
          iconColor="#DC2626"
        />
        <StatCard
          label="Avg. monthly payment"
          value={money(avgMonthlyPayment)}
          note={`Across ${paid.length} collected payment${paid.length === 1 ? "" : "s"}`}
          noteTone="neutral"
          icon={CreditCardIcon}
          iconBg="#DBEAFE"
          iconColor="#2563EB"
        />
      </div>

      {retryError && <p className="text-sm text-red-600">{retryError}</p>}
      <div className="space-y-3">
        {trackingRows.length > 0 && (
          <div className="flex flex-wrap items-center gap-2">
            {FILTERS.map((f) => (
              <button
                key={f.value}
                onClick={() => {
                  setFilter(f.value);
                  setPage(1);
                }}
                aria-pressed={filter === f.value}
                className={`font-heading rounded-full border px-3 py-1.5 text-xs font-bold ${
                  filter === f.value ? "border-red-600 bg-red-600 text-white" : "border-neutral-300 bg-white text-neutral-700 hover:bg-neutral-50"
                }`}
              >
                {f.label} <span className={filter === f.value ? "text-red-100" : "text-neutral-400"}>{groupCounts[f.value]}</span>
              </button>
            ))}
            <input
              type="search"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
              placeholder="Search by customer name"
              aria-label="Search payments by customer name"
              className="ml-auto w-full rounded-md border border-neutral-300 px-3 py-1.5 text-sm sm:w-64"
            />
          </div>
        )}
        <DataTable
          title="Payments to track"
          action={
            trackingRows.length > 0 ? (
              <span className="text-xs text-neutral-400">
                Failed and overdue first, then the next payment due for each lease
              </span>
            ) : undefined
          }
          columns={columns}
          rows={pageRows}
          emptyLabel={trackingRows.length === 0 ? "No payments to track right now." : "No payments match this filter."}
        />
        {shownRows.length > PAGE_SIZE && (
          <div className="flex flex-wrap items-center justify-center gap-3 text-sm text-neutral-500">
            <button
              onClick={() => setPage(Math.max(1, currentPage - 1))}
              disabled={currentPage === 1}
              className="font-semibold text-red-600 hover:underline disabled:text-neutral-300 disabled:no-underline"
            >
              Previous
            </button>
            <span>
              Page {currentPage} of {pageCount} ({shownRows.length} payments)
            </span>
            <button
              onClick={() => setPage(Math.min(pageCount, currentPage + 1))}
              disabled={currentPage === pageCount}
              className="font-semibold text-red-600 hover:underline disabled:text-neutral-300 disabled:no-underline"
            >
              Next
            </button>
          </div>
        )}
      </div>

      {refundNotice && (
        <p role="status" className="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">
          {refundNotice}
        </p>
      )}
      <DataTable title="Recently paid" columns={recentColumns} rows={recentlyPaid} emptyLabel="No payments collected yet." />

      {refunding && (
        <RefundModal
          payment={refunding}
          onClose={() => setRefunding(null)}
          onRefunded={(message) => {
            setRefunding(null);
            setRefundNotice(message);
            void load();
          }}
        />
      )}

      <EarningsChart
        data={earningsData}
        total={money(totalEarnings)}
        avgPerMonth={money(totalEarnings / monthsWithData)}
        bestMonth={{ label: bestMonth.month, value: money(bestMonth.thisYear) }}
      />

      <FundedVolumeChart data={collectionsData} title="Collections" unitLabel="payments collected" tooltipVerb="Collected" />

      {confirming && (
        <Modal
          title={confirming.status === "paid" ? "Mark payment paid" : "Mark payment failed"}
          onClose={() => setConfirming(null)}
          maxWidthClassName="max-w-sm"
        >
          <div className="space-y-4">
            {confirmError && <p className="text-sm text-red-600">{confirmError}</p>}
            <p className="text-sm text-neutral-600">
              {confirming.status === "paid" ? (
                <>
                  Mark {money(Number(confirming.payment.amount))} for{" "}
                  <span className="font-medium text-neutral-900">
                    {confirming.payment.lease_agreement?.customer?.name ?? "this customer"}
                  </span>{" "}
                  as paid?
                </>
              ) : (
                <>
                  Mark {money(Number(confirming.payment.amount))} for{" "}
                  <span className="font-medium text-neutral-900">
                    {confirming.payment.lease_agreement?.customer?.name ?? "this customer"}
                  </span>{" "}
                  as failed? This raises a risk flag on the customer.
                </>
              )}
            </p>
            {confirming.status === "paid" && (
              <div>
                <label htmlFor="paid-by" className="mb-1 block text-xs font-bold uppercase tracking-wide text-neutral-500">
                  Received by
                </label>
                <select
                  id="paid-by"
                  value={paidBy}
                  onChange={(e) => setPaidBy(e.target.value as typeof paidBy)}
                  disabled={confirmBusy}
                  className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"
                >
                  {PAID_BY_OPTIONS.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </select>
              </div>
            )}
            <div className="flex justify-end gap-2">
              <button
                onClick={() => setConfirming(null)}
                disabled={confirmBusy}
                className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                onClick={confirmMark}
                disabled={confirmBusy}
                className={`font-heading rounded-md px-3.5 py-2 text-sm font-bold text-white disabled:opacity-50 ${
                  confirming.status === "paid" ? "bg-green-700 hover:bg-green-800" : "bg-red-600 hover:bg-red-700"
                }`}
              >
                {confirmBusy ? "Saving…" : confirming.status === "paid" ? "Mark Paid" : "Mark Failed"}
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
