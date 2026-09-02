"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { DataTable, type DataTableColumn } from "@/components/dashboard/DataTable";
import { MetricCard } from "@/components/dashboard/MetricCard";
import { ApiError } from "@/lib/api";
import { formatDate, listEquipmentUnits } from "@/lib/equipment";
import type { EquipmentCounts, EquipmentUnit } from "@/types/equipment";

const EMPTY_COUNTS: EquipmentCounts = {
  total: 0,
  in_stock: 0,
  leased: 0,
  returned: 0,
  owned_by_customer: 0,
};

const COLUMNS: DataTableColumn<EquipmentUnit>[] = [
  {
    key: "unit",
    header: "Unit",
    render: (unit) => (
      <Link href={`/admin/equipment/${unit.id}`} className="font-medium text-neutral-900 hover:text-red-600">
        {unit.model}
      </Link>
    ),
  },
  { key: "serial", header: "Serial #", render: (unit) => <span className="font-mono text-neutral-700">{unit.serial_number}</span> },
  {
    key: "customer",
    header: "Customer",
    render: (unit) =>
      unit.current_lease ? (
        <span className="text-neutral-700">{unit.current_lease.customer_name ?? "—"}</span>
      ) : (
        <span className="text-neutral-400">— In stock —</span>
      ),
  },
  {
    key: "condition",
    header: "Condition",
    render: (unit) => <span className="text-neutral-500">{unit.condition_notes ?? "—"}</span>,
  },
  {
    key: "delivered",
    header: "Delivered",
    render: (unit) => <span className="text-neutral-500">{formatDate(unit.delivery_date)}</span>,
  },
];

/** Live equipment snapshot for the admin dashboard. */
export function EquipmentTrackingPanel() {
  const [units, setUnits] = useState<EquipmentUnit[]>([]);
  const [counts, setCounts] = useState<EquipmentCounts>(EMPTY_COUNTS);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    listEquipmentUnits()
      .then((result) => {
        setUnits(result.units);
        setCounts(result.counts);
      })
      .catch((err) => {
        // 403 here just means this admin is restricted away from equipment —
        // say so plainly rather than showing a broken panel.
        setError(
          err instanceof ApiError && err.status === 403
            ? "Your admin account does not include equipment tracking."
            : "Could not load equipment.",
        );
      })
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="py-6 text-sm text-neutral-500">Loading equipment…</p>;
  if (error) return <p className="py-6 text-sm text-neutral-500">{error}</p>;

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <MetricCard value={counts.leased} label="Units leased" barColor="#171717" barPercent={pct(counts.leased, counts.total)} />
        <MetricCard
          value={counts.in_stock}
          label="Available / in stock"
          barColor="#16A34A"
          barPercent={pct(counts.in_stock, counts.total)}
        />
        <MetricCard
          value={counts.returned}
          label="Returned"
          barColor="#D97706"
          barPercent={pct(counts.returned, counts.total)}
        />
      </div>
      <DataTable
        title="Equipment units"
        action={
          <Link href="/admin/equipment" className="font-heading text-sm font-bold text-red-600 hover:underline">
            View all →
          </Link>
        }
        columns={COLUMNS}
        rows={units.slice(0, 6)}
        emptyLabel="No equipment registered yet."
      />
    </div>
  );
}

function pct(value: number, total: number): number {
  return total === 0 ? 0 : Math.round((value / total) * 100);
}
