"use client";

import { useAuth } from "@/context/AuthContext";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { ProfileSettingsCard } from "@/components/account/ProfileSettingsCard";

export default function AdminAccountPage() {
  const { user, refresh } = useAuth();

  return (
    <div className="space-y-6">
      <PageHeroHeader title="My Account" subtitle="Update your own name, email, phone, and password." />
      {user && <ProfileSettingsCard user={user} onUpdated={refresh} />}
    </div>
  );
}
