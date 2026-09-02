"use client";

import { useState, type FormEvent } from "react";
import { Modal } from "@/components/ui/Modal";
import { ApiError } from "@/lib/api";
import { createEquipmentUnit, toDateInput, updateEquipmentUnit } from "@/lib/equipment";
import {
  EARLIEST_TRACKING_DATE,
  MAX_FUTURE_TRACKING_DAYS,
  NOTES_MAX,
  isoDateDaysAhead,
  validateConditionNotes,
  validateEquipmentModel,
  validateGpsDeviceId,
  validateSerialNumber,
  validateTrackingDate,
  validateVin,
} from "@/lib/validation";
import { EQUIPMENT_STATUS_LABELS, type EquipmentStatus, type EquipmentUnit } from "@/types/equipment";

function inputClass(hasError: boolean) {
  return `w-full rounded-md border px-3 py-2 text-sm text-neutral-800 placeholder:text-neutral-400 focus:outline-none ${
    hasError ? "border-red-400 focus:border-red-500" : "border-neutral-200 focus:border-red-300"
  }`;
}
const labelClass = "mb-1 block text-sm font-semibold text-neutral-800";
const errorClass = "mt-1 text-xs text-red-600";

/** Every field the form validates — used to mark everything touched on a blocked submit. */
const FIELDS = [
  "model",
  "serial_number",
  "vin",
  "condition_notes",
  "delivery_date",
  "expected_return_or_ownership_date",
  "gps_device_id",
] as const;

/**
 * Add or edit one unit of equipment. Serial number is the identifier the whole
 * module is keyed on, so it is required and uniqueness is enforced server-side
 * — that error comes back on the `serial_number` key and lands inline.
 *
 * Status is only offered while the unit is off-lease; a unit that is on a lease
 * is moved with assign/release instead, so the lease row and the unit row can
 * never disagree (the API rejects it either way).
 */
export function EquipmentFormModal({
  unit,
  onClose,
  onSaved,
}: {
  unit?: EquipmentUnit | null;
  onClose: () => void;
  onSaved: (saved: EquipmentUnit) => void;
}) {
  const isEdit = !!unit;
  const lockedToLease = !!unit?.current_lease;

  const [form, setForm] = useState({
    model: unit?.model ?? "",
    serial_number: unit?.serial_number ?? "",
    vin: unit?.vin ?? "",
    status: (unit?.status ?? "in_stock") as EquipmentStatus,
    condition_notes: unit?.condition_notes ?? "",
    delivery_date: toDateInput(unit?.delivery_date),
    expected_return_or_ownership_date: toDateInput(unit?.expected_return_or_ownership_date),
    gps_device_id: unit?.gps_device_id ?? "",
  });
  const [error, setError] = useState<string | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [submitting, setSubmitting] = useState(false);

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

  // Rules come from the shared @/lib/validation module — the same functions
  // every other form in the dashboard uses, so a field is never validated one
  // way here and a different way somewhere else.
  const clientErrors: Record<string, string> = {};
  const modelErr = validateEquipmentModel(form.model);
  if (modelErr) clientErrors.model = modelErr;
  const serialErr = validateSerialNumber(form.serial_number);
  if (serialErr) clientErrors.serial_number = serialErr;
  const vinErr = validateVin(form.vin);
  if (vinErr) clientErrors.vin = vinErr;
  const notesErr = validateConditionNotes(form.condition_notes);
  if (notesErr) clientErrors.condition_notes = notesErr;
  const deliveryErr = validateTrackingDate(form.delivery_date, "Delivery date");
  if (deliveryErr) clientErrors.delivery_date = deliveryErr;
  const expectedErr = validateTrackingDate(
    form.expected_return_or_ownership_date,
    "Expected return / ownership date",
  );
  if (expectedErr) clientErrors.expected_return_or_ownership_date = expectedErr;
  const gpsErr = validateGpsDeviceId(form.gps_device_id);
  if (gpsErr) clientErrors.gps_device_id = gpsErr;

  // Cross-field: a unit cannot be due back before it was delivered.
  if (
    !deliveryErr &&
    !expectedErr &&
    form.delivery_date &&
    form.expected_return_or_ownership_date &&
    form.expected_return_or_ownership_date <= form.delivery_date
  ) {
    clientErrors.expected_return_or_ownership_date = "Must be after the delivery date.";
  }

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
      const payload = {
        model: form.model.trim(),
        serial_number: form.serial_number.trim(),
        vin: form.vin.trim() || null,
        condition_notes: form.condition_notes.trim() || null,
        delivery_date: form.delivery_date || null,
        expected_return_or_ownership_date: form.expected_return_or_ownership_date || null,
        gps_device_id: form.gps_device_id.trim() || null,
        // Omitted while the unit sits on a lease — assign/release owns it there.
        ...(lockedToLease ? {} : { status: form.status }),
      };

      const saved = isEdit ? await updateEquipmentUnit(unit!.id, payload) : await createEquipmentUnit(payload);

      onSaved(saved);
      onClose();
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setServerErrors(
          Object.fromEntries(Object.entries(err.errors).map(([key, messages]) => [key, messages[0]])),
        );
      } else {
        setError(err instanceof ApiError ? err.message : "Could not save this unit.");
      }
      setSubmitting(false);
    }
  }

  return (
    <Modal title={isEdit ? "Edit unit" : "Add unit"} onClose={onClose} maxWidthClassName="max-w-xl">
      <p className="-mt-2 mb-5 text-sm text-neutral-500">
        {isEdit
          ? "Update this unit's tracking details."
          : "Register a new unit in the fleet. The serial number is how it is tracked everywhere else."}
      </p>

      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

      <form onSubmit={handleSubmit} autoComplete="off" noValidate className="space-y-5">
        <div>
          <label htmlFor="equipment-model" className={labelClass}>
            Model <span className="text-red-600">*</span>
          </label>
          <input
            id="equipment-model"
            className={inputClass(!!fieldError("model"))}
            placeholder='e.g. Worldlawn Diamondback 60"'
            value={form.model}
            onChange={(e) => set("model", e.target.value)}
            onBlur={() => touch("model")}
            aria-invalid={!!fieldError("model")}
          />
          {fieldError("model") && <p className={errorClass}>{fieldError("model")}</p>}
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="equipment-serial" className={labelClass}>
              Serial Number <span className="text-red-600">*</span>
            </label>
            <input
              id="equipment-serial"
              className={`${inputClass(!!fieldError("serial_number"))} font-mono`}
              placeholder="AGZ3WA18973"
              value={form.serial_number}
              onChange={(e) => set("serial_number", e.target.value)}
              onBlur={() => touch("serial_number")}
              aria-invalid={!!fieldError("serial_number")}
            />
            {fieldError("serial_number") && <p className={errorClass}>{fieldError("serial_number")}</p>}
          </div>
          <div>
            <label htmlFor="equipment-vin" className={labelClass}>
              VIN <span className="font-normal text-neutral-400">(optional)</span>
            </label>
            <input
              id="equipment-vin"
              className={`${inputClass(!!fieldError("vin"))} font-mono`}
              value={form.vin}
              onChange={(e) => set("vin", e.target.value)}
              onBlur={() => touch("vin")}
              aria-invalid={!!fieldError("vin")}
            />
            {fieldError("vin") && <p className={errorClass}>{fieldError("vin")}</p>}
          </div>
        </div>

        <div>
          <label htmlFor="equipment-status" className={labelClass}>
            Status
          </label>
          <select
            id="equipment-status"
            className={`${inputClass(!!fieldError("status"))} disabled:bg-neutral-50 disabled:text-neutral-400`}
            value={form.status}
            disabled={lockedToLease}
            onChange={(e) => set("status", e.target.value as EquipmentStatus)}
          >
            {(Object.keys(EQUIPMENT_STATUS_LABELS) as EquipmentStatus[]).map((status) => (
              <option key={status} value={status}>
                {EQUIPMENT_STATUS_LABELS[status]}
              </option>
            ))}
          </select>
          {lockedToLease ? (
            <p className="mt-1 text-xs text-neutral-500">
              On lease #{unit!.current_lease!.id} — use Assign / Release to change status.
            </p>
          ) : (
            fieldError("status") && <p className={errorClass}>{fieldError("status")}</p>
          )}
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="equipment-delivered" className={labelClass}>
              Delivery Date
            </label>
            <input
              id="equipment-delivered"
              type="date"
              className={inputClass(!!fieldError("delivery_date"))}
              min={EARLIEST_TRACKING_DATE}
              max={isoDateDaysAhead(MAX_FUTURE_TRACKING_DAYS)}
              value={form.delivery_date}
              onChange={(e) => set("delivery_date", e.target.value)}
              onBlur={() => touch("delivery_date")}
              aria-invalid={!!fieldError("delivery_date")}
            />
            {fieldError("delivery_date") && <p className={errorClass}>{fieldError("delivery_date")}</p>}
          </div>
          <div>
            <label htmlFor="equipment-expected" className={labelClass}>
              Expected Return / Ownership
            </label>
            <input
              id="equipment-expected"
              type="date"
              className={inputClass(!!fieldError("expected_return_or_ownership_date"))}
              min={form.delivery_date || EARLIEST_TRACKING_DATE}
              max={isoDateDaysAhead(MAX_FUTURE_TRACKING_DAYS)}
              value={form.expected_return_or_ownership_date}
              onChange={(e) => set("expected_return_or_ownership_date", e.target.value)}
              onBlur={() => touch("expected_return_or_ownership_date")}
              aria-invalid={!!fieldError("expected_return_or_ownership_date")}
            />
            {fieldError("expected_return_or_ownership_date") && (
              <p className={errorClass}>{fieldError("expected_return_or_ownership_date")}</p>
            )}
          </div>
        </div>

        <div>
          <label htmlFor="equipment-condition" className={labelClass}>
            Condition Notes <span className="font-normal text-neutral-400">(internal)</span>
          </label>
          <textarea
            id="equipment-condition"
            rows={3}
            className={inputClass(!!fieldError("condition_notes"))}
            placeholder="e.g. New / 2026, or Used / 2024 — 320 hrs"
            value={form.condition_notes}
            onChange={(e) => set("condition_notes", e.target.value)}
            onBlur={() => touch("condition_notes")}
          />
          <div className="mt-1 flex items-start justify-between gap-2">
            {fieldError("condition_notes") ? <p className={errorClass}>{fieldError("condition_notes")}</p> : <span />}
            <p
              className={`shrink-0 text-xs ${form.condition_notes.length > NOTES_MAX ? "text-red-600" : "text-neutral-400"}`}
            >
              {form.condition_notes.length}/{NOTES_MAX}
            </p>
          </div>
        </div>

        <div className="border-t border-dashed border-neutral-200 pt-5">
          <label htmlFor="equipment-gps" className={labelClass}>
            GPS Device ID <span className="font-normal text-neutral-400">(Phase 2)</span>
          </label>
          <input
            id="equipment-gps"
            className={`${inputClass(!!fieldError("gps_device_id"))} font-mono`}
            placeholder="Stored for the future GPS provider — not tracked yet"
            value={form.gps_device_id}
            onChange={(e) => set("gps_device_id", e.target.value)}
            onBlur={() => touch("gps_device_id")}
            aria-invalid={!!fieldError("gps_device_id")}
          />
          {fieldError("gps_device_id") && <p className={errorClass}>{fieldError("gps_device_id")}</p>}
        </div>

        <div className="flex gap-3 pt-1">
          <button
            type="button"
            onClick={onClose}
            disabled={submitting}
            className="font-heading flex-1 rounded-md border border-neutral-200 py-2.5 text-sm font-bold text-neutral-700 hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={submitting || !isValid}
            className="font-heading flex-1 rounded-md bg-red-600 py-2.5 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {submitting ? "Saving…" : isEdit ? "Save Changes" : "Add Unit"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
