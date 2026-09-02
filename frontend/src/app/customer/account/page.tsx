"use client";

import { useEffect, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { PlaidConnectButton } from "@/components/customer/PlaidConnectButton";
import { ProfileSettingsCard } from "@/components/account/ProfileSettingsCard";
import { updateNotificationPreferences } from "@/lib/notifications";

const inputClass =
  "w-full rounded-md border-0 border-b border-neutral-200 bg-transparent px-0 py-2 text-sm text-neutral-500 focus:outline-none";

export default function CustomerAccountPage() {
  const { user, refresh } = useAuth();
  const profile = user?.customer_profile;
  const [prefs, setPrefs] = useState({
    paymentReminders: profile?.payment_reminder_emails ?? true,
    statusChanges: profile?.status_change_emails ?? true,
  });
  const [saveError, setSaveError] = useState<string | null>(null);

  // The profile loads asynchronously (auth context fetches /auth/me after
  // mount), so the real saved values arrive after this component's first
  // render — sync local state once they show up.
  useEffect(() => {
    if (!profile) return;
    setPrefs({
      paymentReminders: profile.payment_reminder_emails,
      statusChanges: profile.status_change_emails,
    });
  }, [profile?.payment_reminder_emails, profile?.status_change_emails]);

  const address = [profile?.address_line_1, profile?.city, profile?.state, profile?.zip].filter(Boolean).join(", ");

  async function togglePref(key: "paymentReminders" | "statusChanges", checked: boolean) {
    const next = { ...prefs, [key]: checked };
    setPrefs(next);
    setSaveError(null);
    try {
      await updateNotificationPreferences({
        payment_reminder_emails: next.paymentReminders,
        status_change_emails: next.statusChanges,
      });
      await refresh();
    } catch {
      setPrefs(prefs); // revert — the save failed, don't show a preference that isn't actually saved
      setSaveError("Could not save that. Please try again.");
    }
  }

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

      {user && <ProfileSettingsCard user={user} onUpdated={refresh} />}

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <div className="mb-4 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Address &amp; identity</h2>
        </div>

        <div className="space-y-1">
          <div className="flex items-center justify-between border-b border-neutral-100 py-2.5">
            <span className="text-sm text-neutral-400">Mailing address</span>
            <input className={inputClass} style={{ maxWidth: 200 }} value={address || "—"} disabled />
          </div>
        </div>

        <p className="mt-4 text-sm text-neutral-600">
          Address and identity details were entered by your dealer at signup — they&apos;re tied to your signed lease
          paperwork, so contact Outdoor Fix to update them.
        </p>
      </div>

      <div className="rounded-xl border border-neutral-200 bg-white p-5">
        <div className="mb-4 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="font-heading text-base font-bold uppercase tracking-wide text-neutral-900">Bank verification</h2>
        </div>
        <PlaidConnectButton />
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
              onChange={(e) => togglePref("paymentReminders", e.target.checked)}
              className="h-4 w-4 accent-red-600"
            />
            Email me about payment reminders
          </label>
          <label className="flex items-center gap-2.5 text-sm text-neutral-700">
            <input
              type="checkbox"
              checked={prefs.statusChanges}
              onChange={(e) => togglePref("statusChanges", e.target.checked)}
              className="h-4 w-4 accent-red-600"
            />
            Email me when my application status changes
          </label>
          {saveError && <p className="text-xs text-red-600">{saveError}</p>}
        </div>
      </div>
    </div>
  );
}
