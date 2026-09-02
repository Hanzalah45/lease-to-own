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
import { AlertCircleIcon, ArrowUpRightIcon, CheckCircleIcon, ClockIcon, CreditCardIcon } from "@/components/icons";
import { listPayments, markPaymentStatus } from "@/lib/payments";
import { ApiError } from "@/lib/api";
import type { Payment } from "@/types/lease-agreement";

const MONTH_LABELS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

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
      await markPaymentStatus(confirming.payment.id, confirming.status);
      setConfirming(null);
      await load();
    } catch {
      setConfirmError("Could not update that payment. Please try again.");
    } finally {
      setConfirmBusy(false);
    }
  }

  if (loading) return <p className="py-6 text-sm text-neutral-500">Loading payments…</p>;
  if (error) return <p className="py-6 text-sm text-neutral-500">{error}</p>;

  const now = new Date();
  const startOfThisMonth = new Date(now.getFullYear(), now.getMonth(), 1);
  const startOfLastMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);

  const paid = payments.filter((p) => p.status === "paid" && p.paid_date);
  const collectedThisMonth = paid
    .filter((p) => new Date(p.paid_date!) >= startOfThisMonth)
    .reduce((sum, p) => sum + Number(p.amount), 0);
  const collectedLastMonth = paid
    .filter((p) => new Date(p.paid_date!) >= startOfLastMonth && new Date(p.paid_date!) < startOfThisMonth)
    .reduce((sum, p) => sum + Number(p.amount), 0);

  const overduePayments = payments.filter((p) => p.status === "pending" && new Date(p.due_date) < now);

  const leaseIds = new Set(payments.map((p) => p.lease_agreement_id));
  const autopayLeaseIds = new Set(payments.filter((p) => p.lease_agreement?.autopay_enabled).map((p) => p.lease_agreement_id));
  const autopayPct = leaseIds.size === 0 ? 0 : Math.round((autopayLeaseIds.size / leaseIds.size) * 100);

  const avgMonthlyPayment = payments.length === 0 ? 0 : payments.reduce((sum, p) => sum + Number(p.amount), 0) / payments.length;

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

  const overdueRows = [...overduePayments, ...payments.filter((p) => p.status === "pending" && !overduePayments.includes(p))]
    .slice(0, 6);

  const columns: DataTableColumn<Payment>[] = [
    {
      key: "status",
      header: "Status",
      render: (r) => {
        const isOverdue = r.status === "pending" && new Date(r.due_date) < now;
        if (isOverdue) return <StatusTag color="#DC2626" label="Overdue" />;
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
    { key: "amount", header: "Amount", render: (r) => <span className="text-neutral-700">{money(Number(r.amount))}</span> },
    { key: "due", header: "Due", render: (r) => <span className="text-neutral-500">{new Date(r.due_date).toLocaleDateString()}</span> },
    {
      key: "action",
      header: "",
      render: (r) => (
        <div className="flex items-center justify-end gap-3">
          <button
            onClick={() => setConfirming({ payment: r, status: "paid" })}
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
          note={`Across ${payments.length} payment${payments.length === 1 ? "" : "s"}`}
          noteTone="neutral"
          icon={CreditCardIcon}
          iconBg="#DBEAFE"
          iconColor="#2563EB"
        />
      </div>

      <EarningsChart
        data={earningsData}
        total={money(totalEarnings)}
        avgPerMonth={money(totalEarnings / monthsWithData)}
        bestMonth={{ label: bestMonth.month, value: money(bestMonth.thisYear) }}
      />

      <FundedVolumeChart data={collectionsData} title="Collections" unitLabel="payments collected" tooltipVerb="Collected" />

      <DataTable title="Overdue & upcoming payments" columns={columns} rows={overdueRows} emptyLabel="No pending payments." />

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
