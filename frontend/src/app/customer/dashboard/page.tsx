"use client";

import { useAuth } from "@/context/AuthContext";

export default function CustomerDashboardPage() {
  const { user } = useAuth();

  return (
    <div>
      <h1 className="text-2xl font-semibold">Welcome, {user?.name?.split(" ")[0]}</h1>
      <p className="mt-2 text-sm text-neutral-500">
        Your application status, current lease balance, and next payment will show here once the
        Lease &amp; Ownership Engine (Milestone 2) is wired up.
      </p>
    </div>
  );
}
