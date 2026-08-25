"use client";

import Image from "next/image";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { useAuth } from "@/context/AuthContext";
import { dashboardPathForRole } from "@/lib/auth";

export default function Home() {
  const { user, loading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (!loading && user) {
      router.replace(dashboardPathForRole(user.role));
    }
  }, [loading, user, router]);

  return (
    <main
      className="flex flex-1 flex-col items-center justify-center gap-6 px-6 text-center"
      style={{
        background:
          "radial-gradient(circle at 15% 20%, rgba(220,38,38,0.12), transparent 45%), radial-gradient(circle at 85% 75%, rgba(220,38,38,0.10), transparent 45%), #fafafa",
      }}
    >
      {loading ? (
        <p className="text-sm text-neutral-500">Loading…</p>
      ) : (
        <>
          <Image src="/logo.png" alt="Outdoor Fix" width={159} height={103} className="h-24 w-auto" priority />
          <div>
            <p className="font-heading text-xs font-semibold uppercase tracking-widest text-neutral-400">
              Get the equipment. Get to work.
            </p>
            <h1 className="mt-1 text-3xl font-bold uppercase tracking-tight text-neutral-900">
              Outdoor Fix
            </h1>
            <p className="mt-2 text-sm text-neutral-500">Lease-to-own equipment management portal</p>
          </div>
          <div className="flex gap-3">
            <Link
              href="/login"
              className="font-heading rounded-md bg-red-600 px-5 py-2.5 text-base font-bold uppercase tracking-wide text-white transition hover:bg-red-700"
            >
              Sign in
            </Link>
            <Link
              href="/register"
              className="font-heading rounded-md border border-neutral-300 bg-white px-5 py-2.5 text-base font-bold uppercase tracking-wide text-neutral-900 transition hover:bg-neutral-50"
            >
              Create account
            </Link>
          </div>
        </>
      )}
    </main>
  );
}
