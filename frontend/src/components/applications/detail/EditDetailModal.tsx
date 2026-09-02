"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";

export interface EditField {
  key: string;
  label: string;
  value: string;
  type?: "text" | "select" | "textarea";
  options?: string[];
  /**
   * Rule from @/lib/validation. Returns a message when the value is invalid,
   * undefined when it is fine. Supplied per call site so this stays a generic
   * editor while each card still validates against its own API contract.
   */
  validate?: (value: string) => string | undefined;
  /** Shows a live character counter and is enforced by the rule above. */
  maxLength?: number;
  placeholder?: string;
}

function inputClass(hasError: boolean) {
  return `w-full rounded-md border px-3 py-2 text-sm text-neutral-800 placeholder:text-neutral-400 focus:outline-none ${
    hasError ? "border-red-400 focus:border-red-500" : "border-neutral-200 focus:border-red-300"
  }`;
}

export function EditDetailModal({
  title,
  fields,
  onSave,
  onClose,
}: {
  title: string;
  fields: EditField[];
  onSave: (values: Record<string, string>) => void;
  onClose: () => void;
}) {
  const [values, setValues] = useState<Record<string, string>>(() =>
    Object.fromEntries(fields.map((f) => [f.key, f.value]))
  );
  const [touched, setTouched] = useState<Record<string, boolean>>({});

  function set(key: string, value: string) {
    setValues((v) => ({ ...v, [key]: value }));
  }

  function touch(key: string) {
    setTouched((prev) => (prev[key] ? prev : { ...prev, [key]: true }));
  }

  const errors: Record<string, string> = {};
  for (const field of fields) {
    const message = field.validate?.(values[field.key] ?? "");
    if (message) errors[field.key] = message;
  }

  const isValid = Object.keys(errors).length === 0;

  function fieldError(key: string): string | undefined {
    return touched[key] ? errors[key] : undefined;
  }

  function submit() {
    if (!isValid) {
      // Reveal every problem at once rather than one blur at a time.
      setTouched(Object.fromEntries(fields.map((f) => [f.key, true])));
      return;
    }
    onSave(values);
    onClose();
  }

  return (
    <Modal title={`Edit ${title.toLowerCase()}`} onClose={onClose} maxWidthClassName="max-w-lg">
      <div className="space-y-4">
        {fields.map((f) => {
          const error = fieldError(f.key);
          const value = values[f.key] ?? "";
          return (
            <div key={f.key}>
              <label htmlFor={`edit-${f.key}`} className="mb-1 block text-sm font-semibold text-neutral-800">
                {f.label}
              </label>
              {f.type === "select" ? (
                <select
                  id={`edit-${f.key}`}
                  className={inputClass(!!error)}
                  value={value}
                  onChange={(e) => set(f.key, e.target.value)}
                  onBlur={() => touch(f.key)}
                  aria-invalid={!!error}
                >
                  {(f.options ?? []).map((o) => (
                    <option key={o} value={o}>
                      {o}
                    </option>
                  ))}
                </select>
              ) : f.type === "textarea" ? (
                <textarea
                  id={`edit-${f.key}`}
                  rows={3}
                  className={inputClass(!!error)}
                  placeholder={f.placeholder}
                  value={value}
                  onChange={(e) => set(f.key, e.target.value)}
                  onBlur={() => touch(f.key)}
                  aria-invalid={!!error}
                />
              ) : (
                <input
                  id={`edit-${f.key}`}
                  className={inputClass(!!error)}
                  placeholder={f.placeholder}
                  value={value}
                  onChange={(e) => set(f.key, e.target.value)}
                  onBlur={() => touch(f.key)}
                  aria-invalid={!!error}
                />
              )}

              {(error || f.maxLength) && (
                <div className="mt-1 flex items-start justify-between gap-2">
                  {error ? <p className="text-xs text-red-600">{error}</p> : <span />}
                  {f.maxLength && (
                    <p
                      className={`shrink-0 text-xs ${
                        value.length > f.maxLength ? "text-red-600" : "text-neutral-400"
                      }`}
                    >
                      {value.length}/{f.maxLength}
                    </p>
                  )}
                </div>
              )}
            </div>
          );
        })}

        <div className="flex gap-3 pt-2">
          <button
            onClick={onClose}
            className="font-heading flex-1 rounded-md border border-neutral-200 py-2.5 text-sm font-bold text-neutral-700 hover:bg-neutral-50"
          >
            Cancel
          </button>
          <button
            onClick={submit}
            disabled={!isValid}
            className="font-heading flex-1 rounded-md bg-red-600 py-2.5 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            Save Changes
          </button>
        </div>
      </div>
    </Modal>
  );
}
