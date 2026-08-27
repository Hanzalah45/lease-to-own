"use client";

import { useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { CUSTOMER_ADDRESS } from "@/lib/sample-lease";

const inputClass =
  "w-full rounded-md border-0 border-b border-neutral-200 bg-transparent px-0 py-2 text-sm text-neutral-500 focus:outline-none";

export default function CustomerAccountPage() {
  const { user } = useAuth();
  const [prefs, setPrefs] = useState({ paymentReminders: true, statusChanges: true });

  return (
    <div className="space-y-6">
      <div
        className="-mx-4 -mt-4 px-4 pb-6 pt-4 sm:-mx-8 sm:-mt-6 sm:px-8 sm:pt-6 lg:-mx-16 lg:px-16"
        style={{
          background:
            "linear-gradient(to left, rgba(220,38,38,0.24) 0%, rgba(220,38,38,0.10) 35%, transparent 65%)",
        }}
      >
        <h1 className="text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">Account</h1>
        <p className="text-sm text-neutral-400">Your profile and notification preferences.</p>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <div className="mb-4 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Profile</h2>
        </div>

        <div className="space-y-1">
          <div className="flex items-center justify-between border-b border-neutral-100 py-2.5">
            <span className="text-sm text-neutral-400">Name</span>
            <input className={inputClass} style={{ maxWidth: 200 }} value={user?.name ?? ""} disabled />
          </div>
          <div className="flex items-center justify-between border-b border-neutral-100 py-2.5">
            <span className="text-sm text-neutral-400">Email</span>
            <input className={inputClass} style={{ maxWidth: 200 }} value={user?.email ?? ""} disabled />
          </div>
          <div className="flex items-center justify-between border-b border-neutral-100 py-2.5">
            <span className="text-sm text-neutral-400">Cell phone</span>
            <span className="text-sm font-semibold text-neutral-900">{user?.phone ?? "(281) 555-0192"}</span>
          </div>
          <div className="flex items-center justify-between py-2.5">
            <span className="text-sm text-neutral-400">Mailing address</span>
            <input className={inputClass} style={{ maxWidth: 200 }} value={CUSTOMER_ADDRESS} disabled />
          </div>
        </div>

        <p className="mt-4 text-sm text-neutral-600">
          Profile info was entered by your dealer at signup. Contact Outdoor Fix to update it.
        </p>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <div className="mb-4 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Notifications</h2>
        </div>
        <div className="space-y-3">
          <label className="flex items-center gap-2.5 text-sm text-neutral-700">
            <input
              type="checkbox"
              checked={prefs.paymentReminders}
              onChange={(e) => setPrefs((p) => ({ ...p, paymentReminders: e.target.checked }))}
              className="h-4 w-4 accent-red-600"
            />
            Email me about payment reminders
          </label>
          <label className="flex items-center gap-2.5 text-sm text-neutral-700">
            <input
              type="checkbox"
              checked={prefs.statusChanges}
              onChange={(e) => setPrefs((p) => ({ ...p, statusChanges: e.target.checked }))}
              className="h-4 w-4 accent-red-600"
            />
            Email me when my application status changes
          </label>
        </div>
      </div>
    </div>
  );
}
