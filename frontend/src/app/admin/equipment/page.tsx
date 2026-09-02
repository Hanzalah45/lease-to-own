"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { ApiError } from "@/lib/api";
import { deleteEquipmentUnit, formatDate, listEquipmentUnits } from "@/lib/equipment";
import { Modal } from "@/components/ui/Modal";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { StatusTag } from "@/components/dashboard/StatusTag";
import { AssignUnitModal } from "@/components/equipment/AssignUnitModal";
import { EquipmentFormModal } from "@/components/equipment/EquipmentFormModal";
import { ReleaseUnitModal } from "@/components/equipment/ReleaseUnitModal";
import { EyeIcon, PencilIcon, PlusIcon, SearchIcon, TrashIcon } from "@/components/icons";
import {
  EQUIPMENT_STATUS_COLORS,
  EQUIPMENT_STATUS_LABELS,
  type EquipmentCounts,
  type EquipmentStatus,
  type EquipmentUnit,
} from "@/types/equipment";

const EMPTY_COUNTS: EquipmentCounts = {
  total: 0,
  in_stock: 0,
  leased: 0,
  returned: 0,
  owned_by_customer: 0,
};

const FILTERS: { value: EquipmentStatus | "all"; label: string }[] = [
  { value: "all", label: "All" },
  { value: "in_stock", label: "In stock" },
  { value: "leased", label: "Leased" },
  { value: "returned", label: "Returned" },
  { value: "owned_by_customer", label: "Owned" },
];

export default function AdminEquipmentPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  // Mirrors User::hasAdminPermission() on the backend — an admin with no
  // restriction rows has everything; adding rows narrows them to those areas.
  const canTrackEquipment =
    isSuperAdmin || restrictions.length === 0 || restrictions.includes("equipment_tracking");

  const [units, setUnits] = useState<EquipmentUnit[]>([]);
  const [counts, setCounts] = useState<EquipmentCounts>(EMPTY_COUNTS);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<EquipmentStatus | "all">("all");

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<EquipmentUnit | null>(null);
  const [assigning, setAssigning] = useState<EquipmentUnit | null>(null);
  const [releasing, setReleasing] = useState<EquipmentUnit | null>(null);
  const [deleting, setDeleting] = useState<EquipmentUnit | null>(null);

  // Search hits the API (it matches serial, model and VIN server-side), so
  // hold off a beat rather than firing a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300);
    return () => clearTimeout(timer);
  }, [search]);

  // Bumping this re-runs the fetch below — that's how the modals refresh the
  // table once they've saved, without duplicating the request logic.
  const [refreshKey, setRefreshKey] = useState(0);
  const reload = useCallback(() => setRefreshKey((key) => key + 1), []);

  useEffect(() => {
    if (!canTrackEquipment) return;

    // Guards against an out-of-order response overwriting a newer one — easy
    // to hit while typing in the debounced search box.
    let active = true;

    (async () => {
      try {
        const result = await listEquipmentUnits({ status: statusFilter, search: debouncedSearch });
        if (!active) return;
        setUnits(result.units);
        setCounts(result.counts);
        setError(null);
      } catch (err) {
        if (active) setError(err instanceof ApiError ? err.message : "Could not load equipment.");
      } finally {
        if (active) setLoading(false);
      }
    })();

    return () => {
      active = false;
    };
  }, [canTrackEquipment, statusFilter, debouncedSearch, refreshKey]);

  if (!canTrackEquipment) {
    return (
      <div className="space-y-3">
        <h1 className="text-2xl font-bold uppercase tracking-tight">Equipment tracking</h1>
        <p className="max-w-prose text-sm text-neutral-500">
          Your admin account is restricted and does not include equipment tracking. Ask a super admin to add the
          Equipment Tracking permission to your account.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeroHeader
        title="Equipment Tracking"
        subtitle={`Outdoor Fix · ${counts.total} unit${counts.total === 1 ? "" : "s"} in the fleet`}
        action={
          <button
            onClick={() => setCreating(true)}
            className="font-heading flex items-center gap-1.5 self-start rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
          >
            <PlusIcon className="h-4 w-4" />
            Add Unit
          </button>
        }
      >
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <StatCard value={counts.leased} label="Leased out" color="#171717" percent={pct(counts.leased, counts.total)} />
          <StatCard value={counts.in_stock} label="In stock" color="#16A34A" percent={pct(counts.in_stock, counts.total)} />
          <StatCard value={counts.returned} label="Returned" color="#D97706" percent={pct(counts.returned, counts.total)} />
          <StatCard
            value={counts.owned_by_customer}
            label="Owned by customer"
            color="#2563EB"
            percent={pct(counts.owned_by_customer, counts.total)}
          />
        </div>
      </PageHeroHeader>

      <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
        <div className="mb-4 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="text-lg font-bold uppercase tracking-wide text-neutral-900">Equipment units</h2>
        </div>

        <div className="relative mb-4">
          <SearchIcon className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by serial number, model or VIN..."
            className="w-full max-w-sm rounded-md border border-neutral-200 py-2 pl-10 pr-3 text-sm text-neutral-800 placeholder:text-neutral-400 focus:border-red-300 focus:outline-none"
          />
        </div>

        <div className="mb-4 flex flex-wrap gap-2">
          {FILTERS.map((filter) => (
            <button
              key={filter.value}
              onClick={() => setStatusFilter(filter.value)}
              className={`font-heading rounded-full px-3.5 py-1.5 text-xs font-bold uppercase tracking-wide transition ${
                statusFilter === filter.value
                  ? "bg-red-600 text-white"
                  : "bg-neutral-100 text-neutral-600 hover:bg-neutral-200"
              }`}
            >
              {filter.label}
            </button>
          ))}
        </div>

        {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

        {loading ? (
          <p className="py-6 text-sm text-neutral-500">Loading…</p>
        ) : (
          <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <table className="w-full min-w-[860px] text-left text-sm">
              <thead>
                <tr className="font-heading border-b border-neutral-200 text-xs font-bold uppercase tracking-wide text-neutral-400">
                  <th className="pb-2 font-medium">Serial #</th>
                  <th className="pb-2 font-medium">Model</th>
                  <th className="pb-2 font-medium">Status</th>
                  <th className="pb-2 font-medium">Customer</th>
                  <th className="pb-2 font-medium">Delivered</th>
                  <th className="pb-2 font-medium">Service</th>
                  <th className="pb-2 font-medium">Actions</th>
                </tr>
              </thead>
              <tbody>
                {units.map((unit) => (
                  <tr key={unit.id} className="border-b border-neutral-100 last:border-0">
                    <td className="py-3">
                      <Link
                        href={`/admin/equipment/${unit.id}`}
                        className="font-mono font-semibold text-neutral-900 hover:text-red-600"
                      >
                        {unit.serial_number}
                      </Link>
                    </td>
                    <td className="py-3 text-neutral-700">{unit.model}</td>
                    <td className="py-3">
                      <StatusTag
                        color={EQUIPMENT_STATUS_COLORS[unit.status]}
                        label={EQUIPMENT_STATUS_LABELS[unit.status]}
                      />
                    </td>
                    <td className="py-3 text-neutral-500">
                      {unit.current_lease ? (
                        <span>
                          {unit.current_lease.customer_name ?? "—"}
                          <span className="ml-1 text-xs text-neutral-400">#{unit.current_lease.id}</span>
                        </span>
                      ) : (
                        <span className="text-neutral-400">— In stock —</span>
                      )}
                    </td>
                    <td className="py-3 text-neutral-500">{formatDate(unit.delivery_date)}</td>
                    <td className="py-3 text-neutral-500">{unit.service_records_count ?? 0}</td>
                    <td className="py-3">
                      <div className="flex items-center gap-1">
                        <Link
                          href={`/admin/equipment/${unit.id}`}
                          title="View"
                          className="rounded p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700"
                        >
                          <EyeIcon className="h-4 w-4" />
                        </Link>
                        <button
                          onClick={() => setEditing(unit)}
                          title="Edit"
                          className="rounded p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700"
                        >
                          <PencilIcon className="h-4 w-4" />
                        </button>
                        {unit.is_assignable ? (
                          <button
                            onClick={() => setAssigning(unit)}
                            className="font-heading rounded-md bg-neutral-900 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-white hover:bg-neutral-800"
                          >
                            Assign
                          </button>
                        ) : unit.status === "leased" ? (
                          <button
                            onClick={() => setReleasing(unit)}
                            className="font-heading rounded-md border border-neutral-300 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-neutral-700 hover:bg-neutral-50"
                          >
                            Release
                          </button>
                        ) : null}
                        <button
                          onClick={() => setDeleting(unit)}
                          title="Delete"
                          className="rounded p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600"
                        >
                          <TrashIcon className="h-4 w-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
                {units.length === 0 && (
                  <tr>
                    <td colSpan={7} className="py-6 text-center text-sm text-neutral-400">
                      {search || statusFilter !== "all"
                        ? "No units match this search."
                        : "No equipment yet — add your first unit."}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <p className="text-xs text-neutral-400">
        Phase 1 tracks units by serial number. Live GPS location comes in Phase 2 — the GPS device ID field on each
        unit is stored now so the provider can be wired in without a data migration.
      </p>

      {creating && <EquipmentFormModal onClose={() => setCreating(false)} onSaved={reload} />}
      {editing && (
        <EquipmentFormModal unit={editing} onClose={() => setEditing(null)} onSaved={reload} />
      )}
      {assigning && (
        <AssignUnitModal unit={assigning} onClose={() => setAssigning(null)} onAssigned={reload} />
      )}
      {releasing && (
        <ReleaseUnitModal unit={releasing} onClose={() => setReleasing(null)} onReleased={reload} />
      )}
      {deleting && (
        <Modal title="Delete unit" onClose={() => setDeleting(null)} maxWidthClassName="max-w-sm">
          <DeleteUnitConfirm unit={deleting} onCancel={() => setDeleting(null)} onDeleted={() => { setDeleting(null); reload(); }} />
        </Modal>
      )}
    </div>
  );
}

function pct(value: number, total: number): number {
  return total === 0 ? 0 : Math.round((value / total) * 100);
}

function StatCard({ value, label, color, percent }: { value: number; label: string; color: string; percent: number }) {
  return (
    <div className="rounded-xl border border-neutral-200 bg-white px-5 py-4">
      <p className="font-heading text-3xl font-black" style={{ color }}>
        {value}
      </p>
      <p className="mt-1 text-xs font-semibold uppercase tracking-wide text-neutral-500">{label}</p>
      <div className="mt-3 h-1 w-full overflow-hidden rounded-full bg-neutral-100">
        <div className="h-full rounded-full" style={{ width: `${percent}%`, backgroundColor: color }} />
      </div>
    </div>
  );
}

function DeleteUnitConfirm({
  unit,
  onCancel,
  onDeleted,
}: {
  unit: EquipmentUnit;
  onCancel: () => void;
  onDeleted: () => void;
}) {
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleDelete() {
    setError(null);
    setSubmitting(true);
    try {
      await deleteEquipmentUnit(unit.id);
      onDeleted();
    } catch (err) {
      // The API refuses to delete a unit that has lease history — surface that
      // reason rather than a generic failure.
      setError(err instanceof ApiError ? err.message : "Could not delete this unit.");
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-4">
      {error && <p className="text-sm text-red-600">{error}</p>}
      <p className="text-sm text-neutral-600">
        Remove <span className="font-mono font-semibold text-neutral-900">{unit.serial_number}</span> ({unit.model})
        from the fleet? Units that have been on a lease keep their history and cannot be deleted — mark those returned
        instead.
      </p>
      <div className="flex justify-end gap-2">
        <button
          onClick={onCancel}
          className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50"
        >
          Cancel
        </button>
        <button
          onClick={handleDelete}
          disabled={submitting}
          className="font-heading flex items-center gap-1.5 rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
        >
          <TrashIcon className="h-4 w-4" />
          {submitting ? "Deleting…" : "Delete"}
        </button>
      </div>
    </div>
  );
}
