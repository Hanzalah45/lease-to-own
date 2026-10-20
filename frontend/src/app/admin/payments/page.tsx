"use client";

import Link from "next/link";
import { useAuth } from "@/context/AuthContext";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { PaymentTrackingPanel } from "@/components/dashboard/panels/PaymentTrackingPanel";

export default function AdminPaymentsPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  // Mirrors User::hasAdminPermission() on the backend — an admin with no
  // restriction rows has everything; adding rows narrows them to those areas.
  const canTrackPayments = isSuperAdmin || restrictions.length === 0 || restrictions.includes("payment_tracking");

  if (!canTrackPayments) {
    return (
      <div className="space-y-3">
        <h1 className="text-2xl font-bold uppercase tracking-tight">Payments</h1>
        <p className="max-w-prose text-sm text-neutral-500">
          Your admin account is restricted and does not include payment tracking. Ask a super admin to add the
          Payment Tracking permission to your account.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeroHeader
        title="Payments"
        subtitle="Collections, overdue balances, and autopay enrollment across every lease."
        action={
          <div className="flex flex-wrap gap-2 self-start">
            <Link
              href="/admin/autopay-log"
              className="font-heading whitespace-nowrap rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
            >
              AutoPay log →
            </Link>
            <Link
              href="/admin/audit-log"
              className="font-heading whitespace-nowrap rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
            >
              Audit log →
            </Link>
            <Link
              href="/admin/reports"
              className="font-heading whitespace-nowrap rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
            >
              Sales tax &amp; reports →
            </Link>
          </div>
        }
      />
      <PaymentTrackingPanel />
    </div>
  );
}
