"use client";

import { useMemo, useState } from "react";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { MetricCard } from "@/components/dashboard/MetricCard";
import { DocumentIcon, UserIcon } from "@/components/icons";

type Kind = "account" | "application";

interface NotificationRow {
  id: number;
  kind: Kind;
  title: string;
  detail: string;
  when: string;
  decision: "pending" | "accepted" | "declined";
}

const INITIAL_ROWS: NotificationRow[] = [
  { id: 1, kind: "application", title: "Jamal Turner submitted a new lease application", detail: "Baytown, TX · Submitted", when: "4 days ago", decision: "pending" },
  { id: 2, kind: "application", title: "Monica Reyes submitted a new lease application", detail: "League City, TX · Submitted", when: "2 days ago", decision: "pending" },
  { id: 3, kind: "application", title: "Devon Ellis submitted a new lease application", detail: "Pearland, TX · Submitted", when: "Yesterday", decision: "pending" },
  { id: 4, kind: "account", title: "Alicia Chen signed up for a customer account", detail: "alicia.chen@email.com", when: "Yesterday", decision: "accepted" },
  { id: 5, kind: "application", title: "Priya Shah submitted a new lease application", detail: "Katy, TX · Needs Info", when: "5 hrs ago", decision: "pending" },
  { id: 6, kind: "account", title: "Marcus Doyle signed up for a customer account", detail: "marcus.doyle@email.com", when: "2 hrs ago", decision: "pending" },
];

export default function NotificationsPage() {
  const [rows, setRows] = useState<NotificationRow[]>(INITIAL_ROWS);
  const [filter, setFilter] = useState<"all" | Kind>("all");

  const accountPending = rows.filter((r) => r.kind === "account" && r.decision === "pending").length;
  const applicationPending = rows.filter((r) => r.kind === "application" && r.decision === "pending").length;
  const totalPending = accountPending + applicationPending;

  const filtered = useMemo(() => rows.filter((r) => filter === "all" || r.kind === filter), [rows, filter]);

  function decide(id: number, decision: "accepted" | "declined") {
    setRows((prev) => prev.map((r) => (r.id === id ? { ...r, decision } : r)));
  }

  return (
    <div className="space-y-6">
      <PageHeroHeader title="All Notifications" subtitle="Everything needing attention — account requests and submitted applications, together.">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <MetricCard value={accountPending} label="Account requests" barColor="#D97706" barPercent={30} />
          <MetricCard value={applicationPending} label="Application requests" barColor="#DC2626" barPercent={70} />
          <MetricCard value={totalPending} label="Total pending" barColor="#171717" barPercent={100} />
        </div>

        <div className="flex flex-wrap gap-2">
          {(["all", "account", "application"] as const).map((key) => (
            <button
              key={key}
              onClick={() => setFilter(key)}
              className={`font-heading rounded-full px-4 py-1.5 text-xs font-bold uppercase tracking-wide transition ${
                filter === key ? "bg-red-600 text-white" : "border border-red-200 bg-white text-red-600 hover:bg-red-50"
              }`}
            >
              {key === "all" ? "All" : key === "account" ? "Account Requests" : "Application Requests"}
            </button>
          ))}
        </div>
      </PageHeroHeader>

      <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
        <div className="mb-4 flex items-center gap-2">
          <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
          <h2 className="text-lg font-bold uppercase tracking-wide text-neutral-900">Recent</h2>
        </div>
        <div className="divide-y divide-neutral-100">
          {filtered.map((row) => (
            <div key={row.id} className="flex items-center justify-between gap-4 py-4">
              <div className="flex items-center gap-3">
                <span
                  className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${
                    row.kind === "application" ? "bg-red-50 text-red-500" : "bg-blue-50 text-blue-500"
                  }`}
                >
                  {row.kind === "application" ? <DocumentIcon className="h-4 w-4" /> : <UserIcon className="h-4 w-4" />}
                </span>
                <div>
                  <p className="text-sm font-semibold text-neutral-900">{row.title}</p>
                  <p className="text-xs text-neutral-400">
                    {row.detail} · {row.when}
                  </p>
                </div>
              </div>

              {row.decision === "pending" ? (
                <div className="flex shrink-0 gap-2">
                  <button
                    onClick={() => decide(row.id, "accepted")}
                    className="font-heading flex items-center gap-1 rounded-md bg-green-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-green-700"
                  >
                    ✓ Accept
                  </button>
                  <button
                    onClick={() => decide(row.id, "declined")}
                    className="font-heading flex items-center gap-1 rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-100"
                  >
                    ✕ Decline
                  </button>
                </div>
              ) : (
                <span
                  className={`font-heading shrink-0 rounded-full px-3 py-1 text-xs font-bold ${
                    row.decision === "accepted" ? "bg-green-50 text-green-700" : "bg-red-50 text-red-700"
                  }`}
                >
                  {row.decision === "accepted" ? "Accepted" : "Declined"}
                </span>
              )}
            </div>
          ))}
          {filtered.length === 0 && <p className="py-6 text-center text-sm text-neutral-400">Nothing here.</p>}
        </div>
      </div>
    </div>
  );
}
