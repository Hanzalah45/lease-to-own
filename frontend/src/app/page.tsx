"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { useAuth } from "@/context/AuthContext";
import { dashboardPathForRole } from "@/lib/auth";

export default function Home() {
  const { user, loading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (loading) return;
    router.replace(user ? dashboardPathForRole(user.role) : "/login");
  }, [loading, user, router]);

  return (
    <main className="flex flex-1 items-center justify-center">
      <p className="text-sm text-neutral-500">Loading…</p>
    </main>
  );
}
