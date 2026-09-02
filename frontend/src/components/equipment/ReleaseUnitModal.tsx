"use client";

import { useState, type FormEvent } from "react";
import { Modal } from "@/components/ui/Modal";
import { ApiError } from "@/lib/api";
import { releaseEquipmentUnit } from "@/lib/equipment";
import { NOTES_MAX, validateConditionNotes } from "@/lib/validation";
import type { EquipmentReleaseStatus, EquipmentUnit } from "@/types/equipment";

function inputClass(hasError: boolean) {
  return `w-full rounded-md border px-3 py-2 text-sm text-neutral-800 placeholder:text-neutral-400 focus:outline-none ${
    hasError ? "border-red-400 focus:border-red-500" : "border-neutral-200 focus:border-red-300"
  }`;
}
const labelClass = "mb-1 block text-sm font-semibold text-neutral-800";
const errorClass = "mt-1 text-xs text-red-600";

const OPTIONS: { value: EquipmentReleaseStatus; label: string; hint: string }[] = [
  {
    value: "returned",
    label: "Returned",
    hint: "Came back off the lease. The lease no longer holds this unit; it stays out of stock until inspected.",
  },
  {
    value: "in_stock",
    label: "Back in stock",
    hint: "Inspected and ready to go out again on a new lease.",
  },
  {
    value: "owned_by_customer",
    label: "Owned by customer",
    hint: "Paid off — the unit leaves the fleet and stays linked to the lease for history.",
  },
];

/**
 * Takes a unit back off its lease. The three outcomes differ in what happens to
 * the lease link: `owned_by_customer` keeps it (the customer paid it off, the
 * history has to stay attached), the other two detach it.
 */
export function ReleaseUnitModal({
  unit,
  onClose,
  onReleased,
}: {
  unit: EquipmentUnit;
  onClose: () => void;
  onReleased: (saved: EquipmentUnit) => void;
}) {
  const [status, setStatus] = useState<EquipmentReleaseStatus>("returned");
  const [conditionNotes, setConditionNotes] = useState("");
  const [notesTouched, setNotesTouched] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const selected = OPTIONS.find((option) => option.value === status)!;

  // Outcome is a closed set backed by a <select>, so notes length is the only
  // thing that can be invalid here.
  const notesError = validateConditionNotes(conditionNotes);
  const isValid = !notesError;
  const shownNotesError = notesTouched ? notesError : undefined;

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (submitting) return;
    setError(null);

    if (!isValid) {
      setNotesTouched(true);
      return;
    }

    setSubmitting(true);
    try {
      const saved = await releaseEquipmentUnit(unit.id, {
        status,
        condition_notes: conditionNotes.trim() || undefined,
      });
      onReleased(saved);
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not release this unit.");
      setSubmitting(false);
    }
  }

  return (
    <Modal title="Release unit" onClose={onClose} maxWidthClassName="max-w-lg">
      <p className="-mt-2 mb-5 text-sm text-neutral-500">
        Take <span className="font-mono font-semibold text-neutral-900">{unit.serial_number}</span> off
        {unit.current_lease ? (
          <>
            {" "}
            lease #{unit.current_lease.id} ({unit.current_lease.customer_name ?? "unknown customer"}).
          </>
        ) : (
          " its lease."
        )}
      </p>

      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

      <form onSubmit={handleSubmit} noValidate className="space-y-5">
        <div>
          <label htmlFor="release-status" className={labelClass}>
            Outcome <span className="text-red-600">*</span>
          </label>
          <select
            id="release-status"
            className={inputClass(false)}
            value={status}
            onChange={(e) => setStatus(e.target.value as EquipmentReleaseStatus)}
          >
            {OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
          <p className="mt-1 text-xs text-neutral-500">{selected.hint}</p>
        </div>

        <div>
          <label htmlFor="release-notes" className={labelClass}>
            Condition Notes <span className="font-normal text-neutral-400">(optional)</span>
          </label>
          <textarea
            id="release-notes"
            rows={3}
            className={inputClass(!!shownNotesError)}
            placeholder="Leave blank to keep the existing notes"
            value={conditionNotes}
            onChange={(e) => setConditionNotes(e.target.value)}
            onBlur={() => setNotesTouched(true)}
            aria-invalid={!!shownNotesError}
          />
          <div className="mt-1 flex items-start justify-between gap-2">
            {shownNotesError ? <p className={errorClass}>{shownNotesError}</p> : <span />}
            <p
              className={`shrink-0 text-xs ${conditionNotes.length > NOTES_MAX ? "text-red-600" : "text-neutral-400"}`}
            >
              {conditionNotes.length}/{NOTES_MAX}
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
            {submitting ? "Releasing…" : "Release Unit"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
