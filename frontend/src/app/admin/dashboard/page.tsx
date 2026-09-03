"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { AccessTabs, type DashboardTabKey } from "@/components/dashboard/AccessTabs";
import { FundedVolumeChart, type WeekPoint } from "@/components/dashboard/FundedVolumeChart";
import { RecentActivityTable, type ActivityRow } from "@/components/dashboard/RecentActivityTable";
import { StatCard } from "@/components/dashboard/StatCard";
import { ApplicationReviewPanel } from "@/components/dashboard/panels/ApplicationReviewPanel";
import { RiskAssessmentPanel } from "@/components/dashboard/panels/RiskAssessmentPanel";
import { ContractGenerationPanel } from "@/components/dashboard/panels/ContractGenerationPanel";
import { EquipmentTrackingPanel } from "@/components/dashboard/panels/EquipmentTrackingPanel";
import { PaymentTrackingPanel } from "@/components/dashboard/panels/PaymentTrackingPanel";
import { listApplications } from "@/lib/applications";
import { listPayments } from "@/lib/payments";
import { money } from "@/components/applications/wizard/types";
import type { Application, ApplicationStatus } from "@/types/application";
import type { Payment } from "@/types/lease-agreement";
import {
  AlertCircleIcon,
  ArrowUpRightIcon,
  BriefcaseIcon,
  CheckCircleIcon,
  CheckIcon,
  ClockIcon,
  CreditCardIcon,
  PlusIcon,
} from "@/components/icons";

const ACTIVITY_STATUS: Record<ApplicationStatus, ActivityRow["status"]> = {
  submitted: "submitted",
  under_review: "under_review",
  needs_info: "needs_info",
  approved: "approved",
  completed: "funded",
  processed: "funded",
  funded_paid: "funded",
  declined: "declined",
  withdrawn: "withdrawn",
};

function greeting() {
  const hour = new Date().getHours();
  if (hour < 12) return "Good morning";
  if (hour < 18) return "Good afternoon";
  return "Good evening";
}

export default function AdminDashboardPage() {
  const { user } = useAuth();
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  const isSuperAdmin = user?.role === "super_admin";
  const hasFullAccess = isSuperAdmin || restrictions.length === 0;
  const accessLabel = isSuperAdmin
    ? "Super admin · full access"
    : restrictions.length > 0
      ? `Restricted to ${restrictions.length} area${restrictions.length > 1 ? "s" : ""}`
      : "Full access";

  const [activeTab, setActiveTab] = useState<DashboardTabKey>("owner");
  const [zip, setZip] = useState("");
  const router = useRouter();

  const [applications, setApplications] = useState<Application[]>([]);
  const [payments, setPayments] = useState<Payment[]>([]);
  const [ownerDataLoading, setOwnerDataLoading] = useState(false);
  const [ownerDataError, setOwnerDataError] = useState<string | null>(null);

  useEffect(() => {
    if (activeTab !== "owner") return;
    setOwnerDataLoading(true);
    setOwnerDataError(null);
    // A failure here previously reset both lists to [], which rendered
    // identically to a tenant with genuinely zero activity — no way to tell
    // "nothing to review" from "the dashboard couldn't load."
    Promise.all([listApplications(), listPayments()])
      .then(([apps, pmts]) => {
        setApplications(apps);
        setPayments(pmts);
      })
      .catch(() => setOwnerDataError("Could not load dashboard data. Try refreshing the page."))
      .finally(() => setOwnerDataLoading(false));
  }, [activeTab]);

  const needsInfo = applications.filter((a) => a.status === "needs_info");
  const approved = applications.filter((a) => a.status === "approved");
  const fundedApplications = applications.filter((a) => a.status === "funded_paid");
  const fundedVolume = fundedApplications.reduce((sum, a) => sum + Number(a.lease_agreement?.cash_price ?? 0), 0);

  const paidPayments = payments.filter((p) => p.status === "paid" && p.paid_date);
  const now = new Date();
  const collectedLast4Weeks = paidPayments
    .filter((p) => now.getTime() - new Date(p.paid_date!).getTime() < 28 * 24 * 60 * 60 * 1000)
    .reduce((sum, p) => sum + Number(p.amount), 0);
  const collectedPrior4Weeks = paidPayments
    .filter((p) => {
      const diff = now.getTime() - new Date(p.paid_date!).getTime();
      return diff >= 28 * 24 * 60 * 60 * 1000 && diff < 56 * 24 * 60 * 60 * 1000;
    })
    .reduce((sum, p) => sum + Number(p.amount), 0);
  const volumeChangeLabel =
    collectedPrior4Weeks === 0
      ? collectedLast4Weeks > 0
        ? "New activity vs prior 4 wks"
        : "No activity in the last 8 weeks"
      : `${(((collectedLast4Weeks - collectedPrior4Weeks) / collectedPrior4Weeks) * 100).toFixed(0)}% vs prior 4 wks`;

  const weeklyChart: WeekPoint[] = Array.from({ length: 8 }, (_, i) => {
    const weeksAgo = 7 - i;
    const weekStart = new Date(now);
    weekStart.setDate(weekStart.getDate() - weeksAgo * 7 - now.getDay());
    const weekEnd = new Date(weekStart);
    weekEnd.setDate(weekEnd.getDate() + 7);
    const units = paidPayments.filter((p) => {
      const d = new Date(p.paid_date!);
      return d >= weekStart && d < weekEnd;
    }).length;
    return { label: `Wk ${i + 1}`, units };
  });

  const recentActivity: ActivityRow[] = [...applications]
    .sort((a, b) => new Date(b.updated_at).getTime() - new Date(a.updated_at).getTime())
    .slice(0, 8)
    .map((a) => ({
      id: a.id,
      status: ACTIVITY_STATUS[a.status],
      customer: a.customer?.name ?? "—",
      location: a.customer?.customer_profile?.city
        ? `${a.customer.customer_profile.city}, ${a.customer.customer_profile.state ?? ""}`
        : "—",
      price: a.lease_agreement ? money(Number(a.lease_agreement.cash_price)) : "—",
      updated: new Date(a.updated_at).toLocaleDateString(),
    }));

  function startApplication() {
    const query = zip.trim() ? `?zip=${encodeURIComponent(zip.trim())}` : "";
    router.push(`/admin/applications/new${query}`);
  }

  return (
    <div className="space-y-6">
      <div
        className="-mx-4 -mt-4 space-y-6 px-4 pb-6 pt-4 sm:-mx-8 sm:-mt-6 sm:px-8 sm:pt-6 lg:-mx-16 lg:px-16"
        style={{
          background:
            "linear-gradient(to left, rgba(220,38,38,0.24) 0%, rgba(220,38,38,0.10) 35%, transparent 65%)",
        }}
      >
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <p className="font-heading text-sm font-bold uppercase tracking-wide text-red-600">{greeting()}</p>
            <h1 className="mt-1 text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">
              Outdoor Fix Admin
            </h1>
            <p className="text-sm text-neutral-400">
              Signed in as <span className="font-semibold text-neutral-600">{user?.name}</span> · {accessLabel}
            </p>
          </div>
          {activeTab === "owner" ? (
            <Link
              href="/admin/applications/new"
              className="font-heading flex items-center gap-1.5 self-start rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700"
            >
              <PlusIcon className="h-4 w-4" />
              New Application
            </Link>
          ) : (
            <Link
              href="/admin/applications"
              className="font-heading self-start whitespace-nowrap rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
            >
              View All Applications
            </Link>
          )}
        </div>

        <AccessTabs
          ownerLabel={isSuperAdmin ? "Owner" : "Admin"}
          hasFullAccess={hasFullAccess}
          restrictions={restrictions}
          activeKey={activeTab}
          onSelect={setActiveTab}
        />

        {activeTab === "owner" && (
          <div className="flex flex-col gap-3 rounded-xl bg-neutral-950 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div>
              <p className="font-heading text-sm font-bold uppercase tracking-wide text-white">
                Start a new lease application
              </p>
              <p className="text-xs text-neutral-400">Enter the customer&apos;s ZIP to get started.</p>
            </div>
            <div className="flex gap-2">
              <input
                value={zip}
                onChange={(e) => setZip(e.target.value)}
                onKeyDown={(e) => e.key === "Enter" && startApplication()}
                placeholder="Customer ZIP"
                className="w-full min-w-0 flex-1 rounded-md border border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-white placeholder:text-neutral-500 sm:w-36 sm:flex-none"
              />
              <button
                onClick={startApplication}
                className="font-heading shrink-0 rounded-md bg-red-600 px-6 py-2 text-sm font-bold text-white hover:bg-red-700"
              >
                Start →
              </button>
            </div>
          </div>
        )}
      </div>

      {activeTab === "owner" && (
        <>
          {ownerDataLoading && <p className="text-sm text-neutral-400">Loading dashboard…</p>}
          {ownerDataError && <p className="text-sm text-red-600">{ownerDataError}</p>}
          {needsInfo.length > 0 && (
            <div className="flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5">
              <div>
                <p className="text-sm text-neutral-800">
                  <span className="font-bold text-amber-700">
                    {needsInfo.length} application{needsInfo.length === 1 ? "" : "s"}
                  </span>{" "}
                  have items to complete
                </p>
                <p className="text-xs text-neutral-500">
                  Some may already be approved but still need something from you.
                </p>
              </div>
              <Link
                href="/admin/applications"
                className="font-heading self-start whitespace-nowrap rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
              >
                View Pending
              </Link>
            </div>
          )}

          <div className="grid grid-cols-1 divide-y divide-neutral-200 rounded-xl border border-neutral-200 bg-white sm:grid-cols-2 sm:divide-x sm:divide-y-0 lg:grid-cols-4">
            <StatCard
              label="Total applications"
              value={String(applications.length)}
              note={`${fundedApplications.length} funded to date`}
              noteTone="neutral"
              icon={BriefcaseIcon}
              iconBg="#FCE7EE"
              iconColor="#E11D48"
              viewHref="/admin/applications"
            />
            <StatCard
              label="Needs info"
              value={String(needsInfo.length)}
              note={needsInfo.length > 0 ? "Requires immediate action" : "Nothing pending"}
              noteTone={needsInfo.length > 0 ? "warning" : "positive"}
              icon={AlertCircleIcon}
              iconBg="#FEF3C7"
              iconColor="#D97706"
              noteIcon={ClockIcon}
              viewHref="/admin/applications"
            />
            <StatCard
              label="Approved"
              value={String(approved.length)}
              note="Ready for funding"
              noteTone="positive"
              icon={CheckCircleIcon}
              iconBg="#FCE7EE"
              iconColor="#DC2626"
              noteIcon={CheckIcon}
              viewHref="/admin/applications"
            />
            <StatCard
              label="Funded volume"
              value={money(fundedVolume)}
              note={volumeChangeLabel}
              noteTone={collectedLast4Weeks >= collectedPrior4Weeks ? "positive" : "warning"}
              icon={CreditCardIcon}
              iconBg="#DBEAFE"
              iconColor="#2563EB"
              noteIcon={ArrowUpRightIcon}
              viewHref="/admin/payments"
            />
          </div>

          <FundedVolumeChart
            data={weeklyChart}
            total={paidPayments.length}
            growthLabel={volumeChangeLabel}
            title="Payments collected"
            unitLabel="payments collected"
            tooltipVerb="Collected"
          />

          <RecentActivityTable rows={recentActivity} />
        </>
      )}

      {activeTab === "application_review" && <ApplicationReviewPanel />}
      {activeTab === "risk_assessment" && <RiskAssessmentPanel />}
      {activeTab === "contract_generation" && <ContractGenerationPanel />}
      {activeTab === "equipment_tracking" && <EquipmentTrackingPanel />}
      {activeTab === "payment_tracking" && <PaymentTrackingPanel />}
    </div>
  );
}
