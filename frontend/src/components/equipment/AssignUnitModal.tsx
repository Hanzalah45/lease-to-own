"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Modal } from "@/components/ui/Modal";
import { ApiError } from "@/lib/api";
import { assignEquipmentUnit, listAssignableLeases } from "@/lib/equipment";
import {
  EARLIEST_TRACKING_DATE,
  MAX_FUTURE_TRACKING_DAYS,
  NOTES_MAX,
  isoDateDaysAhead,
  validateConditionNotes,
  validateTrackingDate,
} from "@/lib/validation";
import type { AssignableLease, EquipmentUnit } from "@/types/equipment";

function inputClass(hasError: boolean) {
  return `w-full rounded-md border px-3 py-2 text-sm text-neutral-800 placeholder:text-neutral-400 focus:outline-none ${
    hasError ? "border-red-400 focus:border-red-500" : "border-neutral-200 focus:border-red-300"
  }`;
}
const labelClass = "mb-1 block text-sm font-semibold text-neutral-800";
const errorClass = "mt-1 text-xs text-red-600";

const FIELDS = ["lease_agreement_id", "delivery_date", "condition_notes"] as const;

/**
 * Ties a serial number to a customer's lease. Only leases with no unit on them
 * are offered — the API rejects double-assignment either way, this just keeps
 * the impossible option out of the list.
 */
export function AssignUnitModal({
  unit,
  onClose,
  onAssigned,
}: {
  unit: EquipmentUnit;
  onClose: () => void;
  onAssigned: (saved: EquipmentUnit) => void;
}) {
  const [leases, setLeases] = useState<AssignableLease[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState({
    lease_agreement_id: "",
    delivery_date: new Date().toISOString().slice(0, 10),
    condition_notes: "",
  });
  const [error, setError] = useState<string | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    let active = true;
    (async () => {
      try {
        const fetched = await listAssignableLeases();
        if (active) setLeases(fetched);
      } catch (err) {
        if (active) setError(err instanceof ApiError ? err.message : "Could not load leases.");
      } finally {
        if (active) setLoading(false);
      }
    })();
    return () => {
      active = false;
    };
  }, []);

  function set<K extends keyof typeof form>(key: K, value: (typeof form)[K]) {
    setForm((f) => ({ ...f, [key]: value }));
    setServerErrors((prev) => {
      if (!(key in prev)) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
  }

  function touch(key: string) {
    setTouched((prev) => (prev[key] ? prev : { ...prev, [key]: true }));
  }

  const clientErrors: Record<string, string> = {};
  if (!form.lease_agreement_id) clientErrors.lease_agreement_id = "Choose the lease this unit is going on.";
  const deliveryErr = validateTrackingDate(form.delivery_date, "Delivery date");
  if (deliveryErr) clientErrors.delivery_date = deliveryErr;
  const notesErr = validateConditionNotes(form.condition_notes);
  if (notesErr) clientErrors.condition_notes = notesErr;

  const isValid = Object.keys(clientErrors).length === 0;

  function fieldError(key: string): string | undefined {
    return serverErrors[key] ?? (touched[key] ? clientErrors[key] : undefined);
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (submitting) return;
    setError(null);

    if (!isValid) {
      setTouched(Object.fromEntries(FIELDS.map((field) => [field, true])));
      return;
    }

    setSubmitting(true);
    try {
      const saved = await assignEquipmentUnit(unit.id, {
        lease_agreement_id: Number(form.lease_agreement_id),
        delivery_date: form.delivery_date || undefined,
        condition_notes: form.condition_notes.trim() || undefined,
      });
      onAssigned(saved);
      onClose();
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setServerErrors(
          Object.fromEntries(Object.entries(err.errors).map(([key, messages]) => [key, messages[0]])),
        );
      } else {
        setError(err instanceof ApiError ? err.message : "Could not assign this unit.");
      }
      setSubmitting(false);
    }
  }

  return (
    <Modal title="Assign unit" onClose={onClose} maxWidthClassName="max-w-lg">
      <p className="-mt-2 mb-5 text-sm text-neutral-500">
        Put <span className="font-mono font-semibold text-neutral-900">{unit.serial_number}</span> ({unit.model}) on a
        lease. The expected ownership date is calculated from the lease term.
      </p>

      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

      {loading ? (
        <p className="py-6 text-sm text-neutral-500">Loading leases…</p>
      ) : leases.length === 0 ? (
        <div className="space-y-4">
          <p className="rounded-md border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-600">
            Every lease already has a unit assigned. Release a unit first, or approve a new application to create a
            lease.
          </p>
          <button
            onClick={onClose}
            className="font-heading w-full rounded-md border border-neutral-200 py-2.5 text-sm font-bold text-neutral-700 hover:bg-neutral-50"
          >
            Close
          </button>
        </div>
      ) : (
        <form onSubmit={handleSubmit} noValidate className="space-y-5">
          <div>
            <label htmlFor="assign-lease" className={labelClass}>
              Lease <span className="text-red-600">*</span>
            </label>
            <select
              id="assign-lease"
              className={inputClass(!!fieldError("lease_agreement_id"))}
              value={form.lease_agreement_id}
              onChange={(e) => set("lease_agreement_id", e.target.value)}
              onBlur={() => touch("lease_agreement_id")}
              aria-invalid={!!fieldError("lease_agreement_id")}
            >
              <option value="">Select a lease…</option>
              {leases.map((lease) => (
                <option key={lease.id} value={lease.id}>
                  #{lease.id} · {lease.customer_name ?? "Unknown customer"} · {lease.term_months} mo
                </option>
              ))}
            </select>
            {fieldError("lease_agreement_id") && (
              <p className={errorClass}>{fieldError("lease_agreement_id")}</p>
            )}
          </div>

          <div>
            <label htmlFor="assign-delivery" className={labelClass}>
              Delivery Date <span className="text-red-600">*</span>
            </label>
            <input
              id="assign-delivery"
              type="date"
              className={inputClass(!!fieldError("delivery_date"))}
              min={EARLIEST_TRACKING_DATE}
              max={isoDateDaysAhead(MAX_FUTURE_TRACKING_DAYS)}
              value={form.delivery_date}
              onChange={(e) => set("delivery_date", e.target.value)}
              onBlur={() => touch("delivery_date")}
              aria-invalid={!!fieldError("delivery_date")}
            />
            {fieldError("delivery_date") ? (
              <p className={errorClass}>{fieldError("delivery_date")}</p>
            ) : (
              <p className="mt-1 text-xs text-neutral-400">Defaults to today.</p>
            )}
          </div>

          <div>
            <label htmlFor="assign-notes" className={labelClass}>
              Condition at Delivery <span className="font-normal text-neutral-400">(optional)</span>
            </label>
            <textarea
              id="assign-notes"
              rows={2}
              className={inputClass(!!fieldError("condition_notes"))}
              placeholder="Leave blank to keep the existing notes"
              value={form.condition_notes}
              onChange={(e) => set("condition_notes", e.target.value)}
              onBlur={() => touch("condition_notes")}
              aria-invalid={!!fieldError("condition_notes")}
            />
            <div className="mt-1 flex items-start justify-between gap-2">
              {fieldError("condition_notes") ? (
                <p className={errorClass}>{fieldError("condition_notes")}</p>
              ) : (
                <span />
              )}
              <p
                className={`shrink-0 text-xs ${
                  form.condition_notes.length > NOTES_MAX ? "text-red-600" : "text-neutral-400"
                }`}
              >
                {form.condition_notes.length}/{NOTES_MAX}
              </p>
            </div>
          </div>

          <div className="flex gap-3 pt-1">
            <button
              type="button"
              onClick={onClose}
              disabled={submitting}
              className="font-heading flex-1 rounded-md border border-neutral-200 py-2.5 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={submitting || !isValid}
              className="font-heading flex-1 rounded-md bg-red-600 py-2.5 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {submitting ? "Assigning…" : "Assign Unit"}
            </button>
          </div>
        </form>
      )}
    </Modal>
  );
}
