"use client";

import { useState, type FormEvent } from "react";
import { Modal } from "@/components/ui/Modal";
import { ApiError } from "@/lib/api";
import { createCustomer, updateCustomer } from "@/lib/customers";
import type { AuthUser } from "@/types/auth";

const inputClass =
  "w-full rounded-md border border-neutral-200 px-3 py-2 text-sm text-neutral-800 placeholder:text-neutral-400 focus:border-red-300 focus:outline-none";
const labelClass = "mb-1 block text-sm font-semibold text-neutral-800";

export function EditCustomerModal({
  customer,
  onClose,
  onSaved,
}: {
  customer?: AuthUser | null;
  onClose: () => void;
  onSaved: (saved: AuthUser) => void;
}) {
  const isEdit = !!customer;
  const profile = customer?.customer_profile;
  const [form, setForm] = useState({
    name: customer?.name ?? "",
    email: customer?.email ?? "",
    phone: customer?.phone ?? "",
    password: "",
    street: profile?.address_line_1 ?? "",
    city: profile?.city ?? "",
    state: profile?.state ?? "TX",
    zip: profile?.zip ?? "",
    dob: profile?.date_of_birth ?? "",
    notes: profile?.internal_notes ?? "",
  });
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  function set<K extends keyof typeof form>(key: K, value: (typeof form)[K]) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const payload = {
        name: form.name,
        email: form.email,
        phone: form.phone || undefined,
        ...(form.password ? { password: form.password } : {}),
        address_line_1: form.street || undefined,
        city: form.city || undefined,
        state: form.state || undefined,
        zip: form.zip || undefined,
        date_of_birth: form.dob || undefined,
        internal_notes: form.notes || undefined,
      };

      const saved = isEdit
        ? await updateCustomer(customer!.id, payload)
        : await createCustomer({ ...payload, password: form.password });

      onSaved(saved);
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save this customer.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal title={isEdit ? "Edit customer" : "Add customer"} onClose={onClose} maxWidthClassName="max-w-xl">
      <p className="-mt-2 mb-5 text-sm text-neutral-500">
        {isEdit ? "Update this customer's account and contact details." : "Create a new customer account."}
      </p>

      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

      <form onSubmit={handleSubmit} autoComplete="off" className="space-y-5">
        <div>
          <label className={labelClass}>
            Full Name <span className="text-red-600">*</span>
          </label>
          <input
            className={inputClass}
            autoComplete="name"
            value={form.name}
            onChange={(e) => set("name", e.target.value)}
          />
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label className={labelClass}>
              Email <span className="text-red-600">*</span>
            </label>
            <input
              type="email"
              className={inputClass}
              autoComplete="email"
              value={form.email}
              onChange={(e) => set("email", e.target.value)}
            />
          </div>
          <div>
            <label className={labelClass}>Phone</label>
            <input
              type="tel"
              className={inputClass}
              autoComplete="tel"
              placeholder="(000) 000-0000"
              value={form.phone}
              onChange={(e) => set("phone", e.target.value)}
            />
          </div>
        </div>

        <div>
          <label className={labelClass}>
            Password {isEdit ? <span className="font-normal text-neutral-400">(leave blank to keep current)</span> : <span className="text-red-600">*</span>}
          </label>
          <input
            type="password"
            className={inputClass}
            autoComplete="new-password"
            placeholder={isEdit ? "••••••••" : "At least 8 characters"}
            value={form.password}
            onChange={(e) => set("password", e.target.value)}
          />
        </div>

        <div className="border-t border-dashed border-neutral-200 pt-5">
          <p className="mb-3 text-sm font-bold uppercase tracking-wide text-neutral-800">Address</p>
          <div className="space-y-4">
            <div>
              <label className={labelClass}>Street Address</label>
              <input
                className={inputClass}
                autoComplete="address-line1"
                placeholder="Street address"
                value={form.street}
                onChange={(e) => set("street", e.target.value)}
              />
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label className={labelClass}>City</label>
                <input
                  className={inputClass}
                  autoComplete="address-level2"
                  value={form.city}
                  onChange={(e) => set("city", e.target.value)}
                />
              </div>
              <div>
                <label className={labelClass}>State</label>
                <input
                  className={inputClass}
                  autoComplete="address-level1"
                  value={form.state}
                  onChange={(e) => set("state", e.target.value)}
                />
              </div>
            </div>
            <div className="sm:w-1/2 sm:pr-2">
              <label className={labelClass}>Zip Code</label>
              <input
                className={inputClass}
                autoComplete="postal-code"
                value={form.zip}
                onChange={(e) => set("zip", e.target.value)}
              />
            </div>
          </div>
        </div>

        <div className="border-t border-dashed border-neutral-200 pt-5">
          <p className="mb-3 text-sm font-bold uppercase tracking-wide text-neutral-800">Account</p>
          <div>
            <label className={labelClass}>Date of Birth</label>
            <input
              type="date"
              className={inputClass}
              autoComplete="bday"
              value={form.dob}
              onChange={(e) => set("dob", e.target.value)}
            />
          </div>
        </div>

        <div>
          <label className={labelClass}>Internal Notes</label>
          <textarea
            rows={3}
            className={inputClass}
            autoComplete="off"
            placeholder="Optional notes visible only to admins"
            value={form.notes}
            onChange={(e) => set("notes", e.target.value)}
          />
        </div>

        <div className="flex gap-3 pt-1">
          <button
            type="button"
            onClick={onClose}
            className="font-heading flex-1 rounded-md border border-neutral-200 py-2.5 text-sm font-bold text-neutral-700 hover:bg-neutral-50"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={submitting || !form.name || !form.email || (!isEdit && !form.password)}
            className="font-heading flex-1 rounded-md bg-red-600 py-2.5 text-sm font-bold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {submitting ? "Saving…" : isEdit ? "Save Changes" : "Create Customer"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
