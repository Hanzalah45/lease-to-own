import { DataTable, type DataTableColumn } from "@/components/dashboard/DataTable";
import { InertLink } from "@/components/dashboard/InertLink";
import { MetricCard } from "@/components/dashboard/MetricCard";
import { StatusTag } from "@/components/dashboard/StatusTag";

interface RiskRow {
  id: number;
  risk: "borderline" | "high" | "verifying";
  customer: string;
  location: string;
  reason: string;
  since: string;
}

const RISK_STYLE: Record<RiskRow["risk"], { color: string; label: string }> = {
  borderline: { color: "#D97706", label: "Borderline" },
  high: { color: "#DC2626", label: "High" },
  verifying: { color: "#2563EB", label: "Verifying" },
};

const ROWS: RiskRow[] = [
  { id: 1, risk: "borderline", customer: "Brandon Palmer", location: "Dayton, TX", reason: "Address could not be verified", since: "8/5/2026" },
  { id: 2, risk: "high", customer: "Brett Deyo", location: "Wylie, TX", reason: "Prior LTO default on background check", since: "7/31/2026" },
  { id: 3, risk: "verifying", customer: "Kirk Austin", location: "Bayton, TX", reason: "Bank verification (Plaid) in progress", since: "8/3/2026" },
];

const COLUMNS: DataTableColumn<RiskRow>[] = [
  {
    key: "risk",
    header: "Risk",
    render: (r) => <StatusTag color={RISK_STYLE[r.risk].color} label={RISK_STYLE[r.risk].label} />,
  },
  {
    key: "customer",
    header: "Customer",
    render: (r) => (
      <>
        <p className="font-medium text-neutral-900">{r.customer}</p>
        <p className="text-xs text-neutral-400">{r.location}</p>
      </>
    ),
  },
  { key: "reason", header: "Flag reason", render: (r) => <span className="text-neutral-700">{r.reason}</span> },
  { key: "since", header: "Since", render: (r) => <span className="text-neutral-500">{r.since}</span> },
  { key: "action", header: "", render: () => <InertLink className="text-sm">Review →</InertLink> },
];

export function RiskAssessmentPanel() {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <MetricCard value={2} label="Open red flags" barColor="#D97706" barPercent={25} />
        <MetricCard value={4} label="Bank verify pending" barColor="#2563EB" barPercent={45} />
        <MetricCard value={1} label="Background pending" barColor="#171717" barPercent={12} />
        <MetricCard value={47} label="Clear / passed" barColor="#16A34A" barPercent={95} />
      </div>
      <DataTable title="Risk queue" columns={COLUMNS} rows={ROWS} />
    </div>
  );
}
