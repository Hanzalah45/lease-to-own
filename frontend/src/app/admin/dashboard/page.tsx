"use client";

import Link from "next/link";
import { useAuth } from "@/context/AuthContext";
import { FundedVolumeChart, type WeekPoint } from "@/components/dashboard/FundedVolumeChart";
import { RecentActivityTable, type ActivityRow } from "@/components/dashboard/RecentActivityTable";
import { StatCard } from "@/components/dashboard/StatCard";
import {
  AlertCircleIcon,
  ArrowUpRightIcon,
  BriefcaseIcon,
  CheckCircleIcon,
  CheckIcon,
  ClockIcon,
  CreditCardIcon,
} from "@/components/icons";

const SAMPLE_CHART: WeekPoint[] = [
  { label: "Wk 1", units: 3 },
  { label: "Wk 2", units: 5 },
  { label: "Wk 3", units: 4 },
  { label: "Wk 4", units: 6 },
  { label: "Wk 5", units: 6 },
  { label: "Wk 6", units: 7 },
  { label: "Wk 7", units: 7 },
  { label: "Wk 8", units: 0, current: true, target: 8 },
];

const SAMPLE_ACTIVITY: ActivityRow[] = [
  { id: 1, status: "funded", customer: "Robert Kirkland", location: "Conroe, TX", price: "$7,399", updated: "8/6/2026" },
  { id: 2, status: "funded", customer: "Loyd Ellis", location: "Houston, TX", price: "$6,000", updated: "8/5/2026" },
  { id: 3, status: "funded", customer: "Cindy Robles", location: "Cypress, TX", price: "$7,499", updated: "8/5/2026" },
  { id: 4, status: "needs_info", customer: "Brandon Palmer", location: "Dayton, TX", price: "$5,763", updated: "8/5/2026" },
  { id: 5, status: "funded", customer: "Ruben Pena", location: "Willis, TX", price: "$8,990", updated: "8/3/2026" },
];

function greeting() {
  const hour = new Date().getHours();
  if (hour < 12) return "Good morning";
  if (hour < 18) return "Good afternoon";
  return "Good evening";
}

export default function AdminDashboardPage() {
  const { user } = useAuth();
  const permissions = user?.admin_permissions?.map((p) => p.permission) ?? [];

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
              Signed in as <span className="font-semibold text-neutral-600">{user?.name}</span>
              {permissions.length > 0 ? ` · ${permissions.length} permission${permissions.length > 1 ? "s" : ""}` : ""}
            </p>
          </div>
          <Link
            href="/admin/applications"
            className="font-heading self-start rounded-md border border-red-600 bg-white px-4 py-2 text-sm font-bold text-red-600 hover:bg-red-50"
          >
            View All Applications
          </Link>
        </div>

        <div className="flex flex-col gap-3 rounded-xl bg-neutral-950 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
          <div>
            <p className="font-heading text-sm font-bold uppercase tracking-wide text-white">
              Start a new lease application
            </p>
            <p className="text-xs text-neutral-400">Enter the customer&apos;s ZIP to get started.</p>
          </div>
          <div className="flex gap-2">
            <input
              disabled
              placeholder="Customer ZIP"
              className="w-full min-w-0 flex-1 rounded-md border border-neutral-700 bg-neutral-800 px-3 py-2 text-sm text-white placeholder:text-neutral-500 sm:w-36 sm:flex-none"
            />
            <button
              disabled
              title="Coming once the application workflow (Milestone 5) is wired up"
              className="font-heading shrink-0 rounded-md bg-red-600 px-6 py-2 text-sm font-bold text-white"
            >
              Start →
            </button>
          </div>
        </div>
      </div>

      <div className="flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5">
        <div>
          <p className="text-sm text-neutral-800">
            <span className="font-bold text-amber-700">3 applications</span> have items to complete
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

      <div className="grid grid-cols-1 divide-y divide-neutral-200 rounded-xl border border-neutral-200 bg-white sm:grid-cols-2 sm:divide-x sm:divide-y-0 lg:grid-cols-4">
        <StatCard
          label="Total applications"
          value="50"
          note="+12% this month"
          noteTone="positive"
          icon={BriefcaseIcon}
          iconBg="#FCE7EE"
          iconColor="#E11D48"
          noteIcon={ArrowUpRightIcon}
          viewHref="/admin/applications"
        />
        <StatCard
          label="Needs info"
          value="3"
          note="Requires immediate action"
          noteTone="warning"
          icon={AlertCircleIcon}
          iconBg="#FEF3C7"
          iconColor="#D97706"
          noteIcon={ClockIcon}
          viewHref="/admin/applications"
        />
        <StatCard
          label="Approved"
          value="6"
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
          value="$44K"
          note="+44% vs last 4 weeks"
          noteTone="positive"
          icon={CreditCardIcon}
          iconBg="#DBEAFE"
          iconColor="#2563EB"
          noteIcon={ArrowUpRightIcon}
          viewHref="/admin/payments"
        />
      </div>

      <FundedVolumeChart data={SAMPLE_CHART} total={44} growthLabel="+44% vs prior 4 wks" />

      <RecentActivityTable rows={SAMPLE_ACTIVITY} />
    </div>
  );
}
