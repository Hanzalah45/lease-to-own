"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { useAuth } from "@/context/AuthContext";
import { ApiError } from "@/lib/api";
import {
  createServiceRecord,
  deleteServiceRecord,
  formatDate,
  getEquipmentUnit,
} from "@/lib/equipment";
import { StatusTag } from "@/components/dashboard/StatusTag";
import { Modal } from "@/components/ui/Modal";
import { AssignUnitModal } from "@/components/equipment/AssignUnitModal";
import { EquipmentFormModal } from "@/components/equipment/EquipmentFormModal";
import { ReleaseUnitModal } from "@/components/equipment/ReleaseUnitModal";
import { PencilIcon, PlusIcon, TrashIcon } from "@/components/icons";
import {
  EARLIEST_TRACKING_DATE,
  SERVICE_DESCRIPTION_MAX,
  isoDateDaysAgo,
  validateServiceDescription,
  validateTrackingDate,
} from "@/lib/validation";
import {
  EQUIPMENT_STATUS_COLORS,
  EQUIPMENT_STATUS_LABELS,
  type EquipmentUnit,
} from "@/types/equipment";

export default function AdminEquipmentDetailPage() {
  const params = useParams<{ id: string }>();
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  const canTrackEquipment =
    isSuperAdmin || restrictions.length === 0 || restrictions.includes("equipment_tracking");

  const [unit, setUnit] = useState<EquipmentUnit | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);
  const [assigning, setAssigning] = useState(false);
  const [releasing, setReleasing] = useState(false);

  // Bumping this re-runs the fetch below — how the modals and the service log
  // refresh the page after they save. The no-access branch renders before the
  // loading branch, so leaving `loading` true for a restricted admin is never
  // visible.
  const [refreshKey, setRefreshKey] = useState(0);
  const reload = useCallback(() => setRefreshKey((key) => key + 1), []);

  useEffect(() => {
    if (!canTrackEquipment) return;

    let active = true;

    (async () => {
      try {
        const fetched = await getEquipmentUnit(params.id);
        if (!active) return;
        setUnit(fetched);
        setError(null);
      } catch (err) {
        if (active) setError(err instanceof ApiError ? err.message : "Could not load this unit.");
      } finally {
        if (active) setLoading(false);
      }
    })();

    return () => {
      active = false;
    };
  }, [canTrackEquipment, params.id, refreshKey]);

  if (!canTrackEquipment) {
    return (
      <p className="py-12 text-center text-sm text-neutral-500">
        Your admin account does not include equipment tracking.
      </p>
    );
  }
  if (loading) return <p className="py-12 text-center text-sm text-neutral-400">Loading…</p>;
  if (error || !unit) return <p className="py-12 text-center text-sm text-red-600">{error ?? "Unit not found."}</p>;

  const lease = unit.current_lease;

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
            <p className="font-heading text-sm font-bold uppercase tracking-wide text-red-600">Equipment unit</p>
            <h1 className="mt-1 font-mono text-3xl font-black tracking-tight text-neutral-900 sm:text-4xl">
              {unit.serial_number}
            </h1>
            <p className="text-sm text-neutral-500">
              {unit.model} · <StatusTag color={EQUIPMENT_STATUS_COLORS[unit.status]} label={EQUIPMENT_STATUS_LABELS[unit.status]} />
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Link
              href="/admin/equipment"
              className="font-heading self-start whitespace-nowrap rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
            >
              ← All Equipment
            </Link>
            <button
              onClick={() => setEditing(true)}
              className="font-heading flex items-center gap-1.5 rounded-md border border-neutral-300 bg-white px-4 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50"
            >
              <PencilIcon className="h-4 w-4" />
              Edit
            </button>
            {unit.is_assignable && (
              <button
                onClick={() => setAssigning(true)}
                className="font-heading rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
              >
                Assign to Lease
              </button>
            )}
            {unit.status === "leased" && (
              <button
                onClick={() => setReleasing(true)}
                className="font-heading rounded-md bg-neutral-900 px-4 py-2 text-sm font-bold text-white hover:bg-neutral-800"
              >
                Release
              </button>
            )}
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card title="Tracking details">
          <dl className="divide-y divide-neutral-100">
            <Row label="Model" value={unit.model} />
            <Row label="Serial number" value={unit.serial_number} mono />
            <Row label="VIN" value={unit.vin ?? "—"} mono />
            <Row label="Status" value={EQUIPMENT_STATUS_LABELS[unit.status]} />
            <Row label="Delivery date" value={formatDate(unit.delivery_date)} />
            <Row
              label="Expected return / ownership"
              value={formatDate(unit.expected_return_or_ownership_date)}
            />
            <Row label="Condition notes" value={unit.condition_notes ?? "—"} />
            <Row
              label="GPS device ID"
              value={unit.gps_device_id ?? "Not set"}
              hint="Phase 2 — stored now, no live tracking yet."
              mono={!!unit.gps_device_id}
            />
            {unit.updated_by && <Row label="Last edited by" value={unit.updated_by.name} />}
          </dl>
        </Card>

        <Card title="Current assignment">
          {lease ? (
            <dl className="divide-y divide-neutral-100">
              <Row label="Customer" value={lease.customer_name ?? "—"} />
              <Row label="Email" value={lease.customer_email ?? "—"} />
              <Row
                label="Lease"
                value={
                  <Link href={`/admin/applications/${lease.application_id}`} className="font-semibold text-red-600 hover:underline">
                    #{lease.id}
                  </Link>
                }
              />
              <Row label="Application" value={`#${lease.application_id}`} />
              <Row label="Term" value={`${lease.term_months} months`} />
              <Row label="Lease start" value={formatDate(lease.start_date)} />
              <Row label="Next renewal" value={formatDate(lease.renewal_date)} />
              <Row
                label="Ownership"
                value={lease.ownership_status === "owned" ? "Paid off" : "Leasing"}
              />
            </dl>
          ) : (
            <p className="py-6 text-sm text-neutral-500">
              Not on a lease right now.
              {unit.is_assignable
                ? " Use Assign to Lease to put it on a customer's lease."
                : " Release it first to make it available again."}
            </p>
          )}
        </Card>
      </div>

      <ServiceLog unit={unit} onChanged={reload} />

      {editing && <EquipmentFormModal unit={unit} onClose={() => setEditing(false)} onSaved={reload} />}
      {assigning && <AssignUnitModal unit={unit} onClose={() => setAssigning(false)} onAssigned={reload} />}
      {releasing && <ReleaseUnitModal unit={unit} onClose={() => setReleasing(false)} onReleased={reload} />}
    </div>
  );
}

function Card({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
      <div className="mb-3 flex items-center gap-2">
        <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
        <h2 className="text-lg font-bold uppercase tracking-wide text-neutral-900">{title}</h2>
      </div>
      {children}
    </div>
  );
}

function Row({
  label,
  value,
  hint,
  mono,
}: {
  label: string;
  value: React.ReactNode;
  hint?: string;
  mono?: boolean;
}) {
  return (
    <div className="flex items-start justify-between gap-4 py-2.5">
      <dt className="text-sm text-neutral-500">
        {label}
        {hint && <span className="block text-xs text-neutral-400">{hint}</span>}
      </dt>
      <dd className={`text-right text-sm font-semibold text-neutral-900 ${mono ? "font-mono" : ""}`}>{value}</dd>
    </div>
  );
}

function serviceInputClass(hasError: boolean) {
  return `w-full rounded-md border px-3 py-2 text-sm text-neutral-800 placeholder:text-neutral-400 focus:outline-none ${
    hasError ? "border-red-400 focus:border-red-500" : "border-neutral-200 focus:border-red-300"
  }`;
}

/** Simple maintenance log for the unit — date, what was done, who did it. */
function ServiceLog({ unit, onChanged }: { unit: EquipmentUnit; onChanged: () => void }) {
  const records = unit.service_records ?? [];
  const [adding, setAdding] = useState(false);
  const [serviceDate, setServiceDate] = useState(new Date().toISOString().slice(0, 10));
  const [description, setDescription] = useState("");
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [deleting, setDeleting] = useState<{ id: number; description: string } | null>(null);
  const [deletingBusy, setDeletingBusy] = useState(false);

  // A service record documents work already done, so a future date is always
  // a typo — hence allowFuture: false, unlike the delivery/ownership dates.
  const clientErrors: Record<string, string> = {};
  const dateErr = validateTrackingDate(serviceDate, "Service date", { required: true, allowFuture: false });
  if (dateErr) clientErrors.service_date = dateErr;
  const descriptionErr = validateServiceDescription(description);
  if (descriptionErr) clientErrors.description = descriptionErr;

  const isValid = Object.keys(clientErrors).length === 0;

  function fieldError(key: string): string | undefined {
    return touched[key] ? clientErrors[key] : undefined;
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (submitting) return;
    setError(null);

    if (!isValid) {
      setTouched({ service_date: true, description: true });
      return;
    }

    setSubmitting(true);
    try {
      await createServiceRecord(unit.id, { service_date: serviceDate, description: description.trim() });
      setDescription("");
      setTouched({});
      setAdding(false);
      onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save this service record.");
    } finally {
      setSubmitting(false);
    }
  }

  async function confirmDelete() {
    if (!deleting) return;
    setError(null);
    setDeletingBusy(true);
    try {
      await deleteServiceRecord(unit.id, deleting.id);
      setDeleting(null);
      onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not delete this record.");
    } finally {
      setDeletingBusy(false);
    }
  }

  return (
    <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
      <div className="mb-4 flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="text-lg font-bold uppercase tracking-wide text-neutral-900">Service log</h2>
        </div>
        <button
          onClick={() => setAdding((v) => !v)}
          className="font-heading flex items-center gap-1.5 rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700"
        >
          <PlusIcon className="h-4 w-4" />
          {adding ? "Cancel" : "Add Record"}
        </button>
      </div>

      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

      {adding && (
        <form onSubmit={handleSubmit} noValidate className="mb-5 rounded-lg bg-neutral-50 p-4">
          <div className="grid grid-cols-1 items-start gap-3 sm:grid-cols-[10rem_1fr_auto]">
            <div>
              <input
                type="date"
                value={serviceDate}
                onChange={(e) => setServiceDate(e.target.value)}
                onBlur={() => setTouched((prev) => ({ ...prev, service_date: true }))}
                min={EARLIEST_TRACKING_DATE}
                max={isoDateDaysAgo(0)}
                aria-invalid={!!fieldError("service_date")}
                aria-label="Service date"
                className={serviceInputClass(!!fieldError("service_date"))}
              />
              {fieldError("service_date") && (
                <p className="mt-1 text-xs text-red-600">{fieldError("service_date")}</p>
              )}
            </div>
            <div>
              <input
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                onBlur={() => setTouched((prev) => ({ ...prev, description: true }))}
                placeholder="What was done? e.g. Blade sharpening and oil change"
                aria-invalid={!!fieldError("description")}
                aria-label="Work done"
                className={serviceInputClass(!!fieldError("description"))}
              />
              <div className="mt-1 flex items-start justify-between gap-2">
                {fieldError("description") ? (
                  <p className="text-xs text-red-600">{fieldError("description")}</p>
                ) : (
                  <span />
                )}
                <p
                  className={`shrink-0 text-xs ${
                    description.length > SERVICE_DESCRIPTION_MAX ? "text-red-600" : "text-neutral-400"
                  }`}
                >
                  {description.length}/{SERVICE_DESCRIPTION_MAX}
                </p>
              </div>
            </div>
            <button
              type="submit"
              disabled={submitting || !isValid}
              className="font-heading rounded-md bg-neutral-900 px-4 py-2 text-sm font-bold text-white hover:bg-neutral-800 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {submitting ? "Saving…" : "Save"}
            </button>
          </div>
        </form>
      )}

      <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <table className="w-full min-w-[560px] text-left text-sm">
          <thead>
            <tr className="font-heading border-b border-neutral-200 text-xs font-bold uppercase tracking-wide text-neutral-400">
              <th className="pb-2 font-medium">Date</th>
              <th className="pb-2 font-medium">Work done</th>
              <th className="pb-2 font-medium">Logged by</th>
              <th className="pb-2 font-medium" />
            </tr>
          </thead>
          <tbody>
            {records.map((record) => (
              <tr key={record.id} className="border-b border-neutral-100 last:border-0">
                <td className="py-2.5 text-neutral-500">{formatDate(record.service_date)}</td>
                <td className="py-2.5 text-neutral-800">{record.description}</td>
                <td className="py-2.5 text-neutral-500">{record.performed_by_name ?? "—"}</td>
                <td className="py-2.5 text-right">
                  <button
                    onClick={() => setDeleting({ id: record.id, description: record.description })}
                    title="Delete record"
                    className="rounded p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-600"
                  >
                    <TrashIcon className="h-4 w-4" />
                  </button>
                </td>
              </tr>
            ))}
            {records.length === 0 && (
              <tr>
                <td colSpan={4} className="py-6 text-center text-sm text-neutral-400">
                  No service recorded for this unit yet.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {deleting && (
        <Modal title="Delete service record" onClose={() => setDeleting(null)} maxWidthClassName="max-w-sm">
          <div className="space-y-4">
            <p className="text-sm text-neutral-600">
              Remove &ldquo;<span className="font-medium text-neutral-900">{deleting.description}</span>&rdquo; from
              this unit&apos;s service log? This can&apos;t be undone.
            </p>
            <div className="flex justify-end gap-2">
              <button
                onClick={() => setDeleting(null)}
                disabled={deletingBusy}
                className="font-heading rounded-md border border-neutral-300 px-3.5 py-2 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                onClick={confirmDelete}
                disabled={deletingBusy}
                className="font-heading flex items-center gap-1.5 rounded-md bg-red-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-50"
              >
                <TrashIcon className="h-4 w-4" />
                {deletingBusy ? "Deleting…" : "Delete"}
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
