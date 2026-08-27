"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";

export interface EditField {
  key: string;
  label: string;
  value: string;
  type?: "text" | "select" | "textarea";
  options?: string[];
}

const inputClass =
  "w-full rounded-md border border-neutral-200 px-3 py-2 text-sm text-neutral-800 placeholder:text-neutral-400 focus:border-red-300 focus:outline-none";

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

  function set(key: string, value: string) {
    setValues((v) => ({ ...v, [key]: value }));
  }

  function submit() {
    onSave(values);
    onClose();
  }

  return (
    <Modal title={`Edit ${title.toLowerCase()}`} onClose={onClose} maxWidthClassName="max-w-lg">
      <div className="space-y-4">
        {fields.map((f) => (
          <div key={f.key}>
            <label className="mb-1 block text-sm font-semibold text-neutral-800">{f.label}</label>
            {f.type === "select" ? (
              <select className={inputClass} value={values[f.key]} onChange={(e) => set(f.key, e.target.value)}>
                {(f.options ?? []).map((o) => (
                  <option key={o} value={o}>
                    {o}
                  </option>
                ))}
              </select>
            ) : f.type === "textarea" ? (
              <textarea
                rows={3}
                className={inputClass}
                value={values[f.key]}
                onChange={(e) => set(f.key, e.target.value)}
              />
            ) : (
              <input className={inputClass} value={values[f.key]} onChange={(e) => set(f.key, e.target.value)} />
            )}
          </div>
        ))}

        <div className="flex gap-3 pt-2">
          <button
            onClick={onClose}
            className="font-heading flex-1 rounded-md border border-neutral-200 py-2.5 text-sm font-bold text-neutral-700 hover:bg-neutral-50"
          >
            Cancel
          </button>
          <button
            onClick={submit}
            className="font-heading flex-1 rounded-md bg-red-600 py-2.5 text-sm font-bold text-white hover:bg-red-700"
          >
            Save Changes
          </button>
        </div>
      </div>
    </Modal>
  );
}
