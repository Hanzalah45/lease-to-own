import { DataTable, type DataTableColumn } from "@/components/dashboard/DataTable";
import { InertLink } from "@/components/dashboard/InertLink";
import { MetricCard } from "@/components/dashboard/MetricCard";
import { StatusTag } from "@/components/dashboard/StatusTag";

interface ContractRow {
  id: number;
  status: "awaiting" | "signed";
  customer: string;
  location: string;
  document: string;
  term: string;
}

const STATUS_STYLE: Record<ContractRow["status"], { color: string; label: string }> = {
  awaiting: { color: "#D97706", label: "Awaiting sig." },
  signed: { color: "#16A34A", label: "Signed" },
};

const ROWS: ContractRow[] = [
  { id: 1, status: "awaiting", customer: "Brandon Palmer", location: "Dayton, TX", document: "Lease Purchase Agreement v1", term: "36 mo" },
  { id: 2, status: "awaiting", customer: "Kirk Austin", location: "Bayton, TX", document: "Lease Purchase Agreement v1", term: "24 mo" },
  { id: 3, status: "signed", customer: "Brett Deyo", location: "Wylie, TX", document: "Lease Purchase Agreement v1", term: "36 mo" },
];

const COLUMNS: DataTableColumn<ContractRow>[] = [
  {
    key: "status",
    header: "Status",
    render: (r) => <StatusTag color={STATUS_STYLE[r.status].color} label={STATUS_STYLE[r.status].label} />,
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
  { key: "document", header: "Document", render: (r) => <span className="text-neutral-700">{r.document}</span> },
  { key: "term", header: "Term", render: (r) => <span className="text-neutral-500">{r.term}</span> },
  {
    key: "action",
    header: "",
    render: (r) => <InertLink className="text-sm">{r.status === "signed" ? "View →" : "Open →"}</InertLink>,
  },
];

export function ContractGenerationPanel() {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <MetricCard value={2} label="Awaiting signature" barColor="#D97706" barPercent={25} />
        <MetricCard value={5} label="Signed this week" barColor="#16A34A" barPercent={55} />
        <MetricCard value={34} label="Total signed on file" barColor="#171717" barPercent={95} />
      </div>
      <DataTable title="Contracts pending generation / signature" columns={COLUMNS} rows={ROWS} />
    </div>
  );
}
