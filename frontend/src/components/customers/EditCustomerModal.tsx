"use client";

import { useState, type FormEvent } from "react";
import { Modal } from "@/components/ui/Modal";
import { EyeIcon, EyeOffIcon } from "@/components/icons";
import { ApiError } from "@/lib/api";
import { createCustomer, updateCustomer } from "@/lib/customers";
import {
  NOTES_MAX,
  isoDateDaysAgo,
  validateCity,
  validateDob,
  validateEmail,
  validateName,
  validateNotes,
  validatePassword,
  validatePhone,
  validateState,
  validateStreet,
  validateZip,
} from "@/lib/validation";
import type { AuthUser } from "@/types/auth";

function inputClass(hasError: boolean) {
  return `w-full rounded-md border px-3 py-2 text-sm text-neutral-800 placeholder:text-neutral-400 focus:outline-none ${
    hasError ? "border-red-400 focus:border-red-500" : "border-neutral-200 focus:border-red-300"
  }`;
}
const labelClass = "mb-1 block text-sm font-semibold text-neutral-800";
const errorClass = "mt-1 text-xs text-red-600";

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
  const [showPassword, setShowPassword] = useState(false);
  const [form, setForm] = useState({
    name: customer?.name ?? "",
    email: customer?.email ?? "",
    phone: customer?.phone ?? "",
    password: "",
    street: profile?.address_line_1 ?? "",
    city: profile?.city ?? "",
    state: profile?.state ?? "TX",
    zip: profile?.zip ?? "",
    // The API serializes this as a full ISO datetime (e.g.
    // "1990-05-15T00:00:00.000000Z"); <input type="date"> only accepts a
    // bare "YYYY-MM-DD" and silently renders blank on anything else.
    dob: profile?.date_of_birth ? profile.date_of_birth.slice(0, 10) : "",
    notes: profile?.internal_notes ?? "",
  });
  const [error, setError] = useState<string | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [submitting, setSubmitting] = useState(false);

  function set<K extends keyof typeof form>(key: K, value: (typeof form)[K]) {
    setForm((f) => ({ ...f, [key]: value }));
    clearServerError(key);
  }

  function touch(key: string) {
    setTouched((prev) => (prev[key] ? prev : { ...prev, [key]: true }));
  }

  function clearServerError(key: string) {
    setServerErrors((prev) => {
      if (!(key in prev)) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
  }

  // Rules come from the shared @/lib/validation module — the same functions
  // every form in the dashboard uses, so a field is never validated one way
  // here and a different way somewhere else. Length caps mirror
  // CustomerController::validate() exactly; required-ness and character-class
  // checks (letters-only names, digits-only phone, etc.) are a stricter
  // client-side layer on top — the API still accepts these as nullable so
  // existing incomplete profiles can still be opened, but the form won't
  // let you save without them.
  const clientErrors: Record<string, string> = {};
  const nameErr = validateName(form.name, "Full name");
  if (nameErr) clientErrors.name = nameErr;
  const emailErr = validateEmail(form.email);
  if (emailErr) clientErrors.email = emailErr;
  const phoneErr = validatePhone(form.phone, true);
  if (phoneErr) clientErrors.phone = phoneErr;
  const passwordErr = validatePassword(form.password, !isEdit);
  if (passwordErr) clientErrors.password = passwordErr;
  const streetErr = validateStreet(form.street);
  if (streetErr) clientErrors.address_line_1 = streetErr;
  const cityErr = validateCity(form.city);
  if (cityErr) clientErrors.city = cityErr;
  const stateErr = validateState(form.state);
  if (stateErr) clientErrors.state = stateErr;
  const zipErr = validateZip(form.zip);
  if (zipErr) clientErrors.zip = zipErr;
  const dobErr = validateDob(form.dob);
  if (dobErr) clientErrors.dob = dobErr;
  const notesErr = validateNotes(form.notes);
  if (notesErr) clientErrors.notes = notesErr;

  const isValid = Object.keys(clientErrors).length === 0;

  function fieldError(key: string): string | undefined {
    return serverErrors[key] ?? (touched[key] ? clientErrors[key] : undefined);
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (submitting) return; // guard against a double-click/double-Enter race
    setError(null);

    if (!isValid) {
      setTouched({
        name: true,
        email: true,
        phone: true,
        password: true,
        address_line_1: true,
        city: true,
        state: true,
        zip: true,
        dob: true,
        notes: true,
      });
      return;
    }

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
      if (err instanceof ApiError && err.errors) {
        // Each message is already shown inline under its field — no need
        // to repeat it in a summary banner too. The backend keys its errors
        // by the payload field name, which for two fields differs from the
        // form's own state key (date_of_birth -> dob, internal_notes ->
        // notes) — translate those so the error still lands under the
        // right input instead of silently going nowhere.
        const BACKEND_TO_FORM_KEY: Record<string, string> = {
          date_of_birth: "dob",
          internal_notes: "notes",
        };
        setServerErrors(
          Object.fromEntries(
            Object.entries(err.errors).map(([key, messages]) => [BACKEND_TO_FORM_KEY[key] ?? key, messages[0]]),
          ),
        );
      } else {
        setError(err instanceof ApiError ? err.message : "Could not save this customer.");
      }
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

      <form onSubmit={handleSubmit} autoComplete="off" noValidate className="space-y-5">
        <div>
          <label htmlFor="customer-name" className={labelClass}>
            Full Name <span className="text-red-600">*</span>
          </label>
          <input
            id="customer-name"
            className={inputClass(!!fieldError("name"))}
            autoComplete="name"
            value={form.name}
            onChange={(e) => set("name", e.target.value)}
            onBlur={() => touch("name")}
            aria-invalid={!!fieldError("name")}
            aria-describedby={fieldError("name") ? "customer-name-error" : undefined}
          />
          {fieldError("name") && (
            <p id="customer-name-error" className={errorClass}>
              {fieldError("name")}
            </p>
          )}
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label htmlFor="customer-email" className={labelClass}>
              Email <span className="text-red-600">*</span>
            </label>
            <input
              id="customer-email"
              type="email"
              className={inputClass(!!fieldError("email"))}
              autoComplete="email"
              value={form.email}
              onChange={(e) => set("email", e.target.value)}
              onBlur={() => touch("email")}
              aria-invalid={!!fieldError("email")}
              aria-describedby={fieldError("email") ? "customer-email-error" : undefined}
            />
            {fieldError("email") && (
              <p id="customer-email-error" className={errorClass}>
                {fieldError("email")}
              </p>
            )}
          </div>
          <div>
            <label htmlFor="customer-phone" className={labelClass}>
              Phone <span className="text-red-600">*</span>
            </label>
            <input
              id="customer-phone"
              type="tel"
              className={inputClass(!!fieldError("phone"))}
              autoComplete="tel"
              placeholder="(000) 000-0000"
              value={form.phone}
              onChange={(e) => set("phone", e.target.value)}
              onBlur={() => touch("phone")}
              aria-invalid={!!fieldError("phone")}
              aria-describedby={fieldError("phone") ? "customer-phone-error" : undefined}
            />
            {fieldError("phone") && (
              <p id="customer-phone-error" className={errorClass}>
                {fieldError("phone")}
              </p>
            )}
          </div>
        </div>

        <div>
          <label htmlFor="customer-password" className={labelClass}>
            Password {isEdit ? <span className="font-normal text-neutral-400">(leave blank to keep current)</span> : <span className="text-red-600">*</span>}
          </label>
          <div className="relative">
            <input
              id="customer-password"
              type={showPassword ? "text" : "password"}
              className={`${inputClass(!!fieldError("password"))} pr-10`}
              autoComplete="new-password"
              placeholder={isEdit ? "••••••••" : "At least 8 characters, incl. a letter and a number"}
              value={form.password}
              onChange={(e) => set("password", e.target.value)}
              onBlur={() => touch("password")}
              aria-invalid={!!fieldError("password")}
              aria-describedby={fieldError("password") ? "customer-password-error" : undefined}
            />
            <button
              type="button"
              onClick={() => setShowPassword((v) => !v)}
              tabIndex={-1}
              aria-label={showPassword ? "Hide password" : "Show password"}
              className="absolute inset-y-0 right-0 flex items-center px-3 text-neutral-400 hover:text-neutral-600"
            >
              {showPassword ? <EyeOffIcon className="h-4 w-4" /> : <EyeIcon className="h-4 w-4" />}
            </button>
          </div>
          {fieldError("password") && (
            <p id="customer-password-error" className={errorClass}>
              {fieldError("password")}
            </p>
          )}
        </div>

        <div className="border-t border-dashed border-neutral-200 pt-5">
          <p className="mb-3 text-sm font-bold uppercase tracking-wide text-neutral-800">Address</p>
          <div className="space-y-4">
            <div>
              <label htmlFor="customer-street" className={labelClass}>
                Street Address <span className="text-red-600">*</span>
              </label>
              <input
                id="customer-street"
                className={inputClass(!!fieldError("address_line_1"))}
                autoComplete="address-line1"
                placeholder="Street address"
                value={form.street}
                onChange={(e) => set("street", e.target.value)}
                onBlur={() => touch("address_line_1")}
                aria-invalid={!!fieldError("address_line_1")}
                aria-describedby={fieldError("address_line_1") ? "customer-street-error" : undefined}
              />
              {fieldError("address_line_1") && (
                <p id="customer-street-error" className={errorClass}>
                  {fieldError("address_line_1")}
                </p>
              )}
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label htmlFor="customer-city" className={labelClass}>
                  City <span className="text-red-600">*</span>
                </label>
                <input
                  id="customer-city"
                  className={inputClass(!!fieldError("city"))}
                  autoComplete="address-level2"
                  value={form.city}
                  onChange={(e) => set("city", e.target.value)}
                  onBlur={() => touch("city")}
                  aria-invalid={!!fieldError("city")}
                  aria-describedby={fieldError("city") ? "customer-city-error" : undefined}
                />
                {fieldError("city") && (
                  <p id="customer-city-error" className={errorClass}>
                    {fieldError("city")}
                  </p>
                )}
              </div>
              <div>
                <label htmlFor="customer-state" className={labelClass}>
                  State <span className="text-red-600">*</span>
                </label>
                <input
                  id="customer-state"
                  className={inputClass(!!fieldError("state"))}
                  autoComplete="address-level1"
                  placeholder="TX"
                  value={form.state}
                  onChange={(e) => set("state", e.target.value.toUpperCase())}
                  onBlur={() => touch("state")}
                  aria-invalid={!!fieldError("state")}
                  aria-describedby={fieldError("state") ? "customer-state-error" : undefined}
                />
                {fieldError("state") && (
                  <p id="customer-state-error" className={errorClass}>
                    {fieldError("state")}
                  </p>
                )}
              </div>
            </div>
            <div className="sm:w-1/2 sm:pr-2">
              <label htmlFor="customer-zip" className={labelClass}>
                Zip Code <span className="text-red-600">*</span>
              </label>
              <input
                id="customer-zip"
                className={inputClass(!!fieldError("zip"))}
                autoComplete="postal-code"
                placeholder="12345"
                value={form.zip}
                onChange={(e) => set("zip", e.target.value)}
                onBlur={() => touch("zip")}
                aria-invalid={!!fieldError("zip")}
                aria-describedby={fieldError("zip") ? "customer-zip-error" : undefined}
              />
              {fieldError("zip") && (
                <p id="customer-zip-error" className={errorClass}>
                  {fieldError("zip")}
                </p>
              )}
            </div>
          </div>
        </div>

        <div className="border-t border-dashed border-neutral-200 pt-5">
          <p className="mb-3 text-sm font-bold uppercase tracking-wide text-neutral-800">Account</p>
          <div>
            <label htmlFor="customer-dob" className={labelClass}>
              Date of Birth <span className="text-red-600">*</span>
            </label>
            <input
              id="customer-dob"
              type="date"
              className={inputClass(!!fieldError("dob"))}
              autoComplete="bday"
              min={isoDateDaysAgo(365 * 120)}
              max={isoDateDaysAgo(0)}
              value={form.dob}
              onChange={(e) => set("dob", e.target.value)}
              onBlur={() => touch("dob")}
              aria-invalid={!!fieldError("dob")}
              aria-describedby={fieldError("dob") ? "customer-dob-error" : undefined}
            />
            {fieldError("dob") && (
              <p id="customer-dob-error" className={errorClass}>
                {fieldError("dob")}
              </p>
            )}
          </div>
        </div>

        <div>
          <label htmlFor="customer-notes" className={labelClass}>
            Internal Notes
          </label>
          <textarea
            id="customer-notes"
            rows={3}
            className={inputClass(!!fieldError("notes"))}
            autoComplete="off"
            placeholder="Optional notes visible only to admins"
            value={form.notes}
            onChange={(e) => set("notes", e.target.value)}
            onBlur={() => touch("notes")}
            aria-invalid={!!fieldError("notes")}
            aria-describedby={fieldError("notes") ? "customer-notes-error customer-notes-counter" : "customer-notes-counter"}
          />
          <div className="mt-1 flex items-start justify-between gap-2">
            {fieldError("notes") ? (
              <p id="customer-notes-error" className={errorClass}>
                {fieldError("notes")}
              </p>
            ) : (
              <span />
            )}
            <p
              id="customer-notes-counter"
              className={`shrink-0 text-xs ${form.notes.length > NOTES_MAX ? "text-red-600" : "text-neutral-400"}`}
            >
              {form.notes.length}/{NOTES_MAX}
            </p>
          </div>
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
            {submitting ? "Saving…" : isEdit ? "Save Changes" : "Create Customer"}
          </button>
        </div>
      </form>
    </Modal>
  );
}
