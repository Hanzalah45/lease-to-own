"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { ApiError } from "@/lib/api";
import { formatDate, listMyEquipment } from "@/lib/equipment";
import { StatusTag } from "@/components/dashboard/StatusTag";
import {
  EQUIPMENT_STATUS_COLORS,
  EQUIPMENT_STATUS_LABELS,
  type CustomerEquipmentUnit,
} from "@/types/equipment";

/**
 * Read-only view of the equipment on this customer's own leases. Everything
 * here is maintained by staff — the customer portal has no write path into
 * equipment at all (see Api\Customer\EquipmentController).
 */
export default function CustomerEquipmentPage() {
  const { user } = useAuth();
  const [units, setUnits] = useState<CustomerEquipmentUnit[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    listMyEquipment()
      .then(setUnits)
      .catch((err) => setError(err instanceof ApiError ? err.message : "Could not load your equipment."))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="space-y-6">
      <div
        className="-mx-4 -mt-4 px-4 pb-6 pt-4 sm:-mx-8 sm:-mt-6 sm:px-8 sm:pt-6 lg:-mx-16 lg:px-16"
        style={{
          background:
            "linear-gradient(to left, rgba(220,38,38,0.24) 0%, rgba(220,38,38,0.10) 35%, transparent 65%)",
        }}
      >
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <p className="font-heading text-sm font-bold uppercase tracking-wide text-red-600">Your equipment</p>
            <h1 className="mt-1 text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">
              {user?.name ?? "My Equipment"}
            </h1>
            <p className="text-sm text-neutral-400">
              Every unit currently on your lease, tracked by serial number.
            </p>
          </div>
          <Link
            href="/customer/dashboard"
            className="font-heading self-start whitespace-nowrap rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
          >
            ← Back to Dashboard
          </Link>
        </div>
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

      {loading ? (
        <p className="py-12 text-center text-sm text-neutral-400">Loading…</p>
      ) : units.length === 0 ? (
        <div className="rounded-xl border border-neutral-200 bg-white p-8 text-center">
          <p className="text-sm text-neutral-500">
            No equipment is assigned to you yet. Once your lease is approved and a unit is delivered, its serial
            number and delivery details will show up here.
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          {units.map((unit) => (
            <div key={unit.id} className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-5">
              <div className="mb-4 flex items-start justify-between gap-3">
                <div>
                  <h2 className="text-lg font-bold uppercase tracking-wide text-neutral-900">{unit.model}</h2>
                  <p className="font-mono text-sm text-neutral-500">{unit.serial_number}</p>
                </div>
                <StatusTag
                  color={EQUIPMENT_STATUS_COLORS[unit.status]}
                  label={EQUIPMENT_STATUS_LABELS[unit.status]}
                />
              </div>

              <dl className="divide-y divide-neutral-100">
                <Row label="Serial number" value={unit.serial_number} mono />
                {unit.vin && <Row label="VIN" value={unit.vin} mono />}
                <Row label="Delivered" value={formatDate(unit.delivery_date)} />
                <Row
                  label={unit.lease_ownership_status === "owned" ? "Ownership date" : "Expected ownership"}
                  value={formatDate(unit.expected_return_or_ownership_date)}
                />
                <Row label="Lease term" value={`${unit.lease_term_months} months`} />
                <Row label="Last serviced" value={formatDate(unit.last_service_date)} />
              </dl>

              <Link
                href={`/customer/leases/${unit.lease_agreement_id}`}
                className="font-heading mt-4 inline-flex rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50"
              >
                View lease #{unit.lease_agreement_id}
              </Link>
            </div>
          ))}
        </div>
      )}

      <p className="text-xs text-neutral-400">
        Condition and service details are maintained by Outdoor Fix. If something here looks wrong, contact your
        account manager.
      </p>
    </div>
  );
}

function Row({ label, value, mono }: { label: string; value: React.ReactNode; mono?: boolean }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2.5">
      <dt className="text-sm text-neutral-500">{label}</dt>
      <dd className={`text-right text-sm font-semibold text-neutral-900 ${mono ? "font-mono" : ""}`}>{value}</dd>
    </div>
  );
}
