import { DataTable, type DataTableColumn } from "@/components/dashboard/DataTable";
import { InertLink } from "@/components/dashboard/InertLink";
import { MetricCard } from "@/components/dashboard/MetricCard";
import { StatusTag } from "@/components/dashboard/StatusTag";

interface AppRow {
  id: number;
  status: "needs_info" | "approved";
  customer: string;
  equipment: string;
  price: string;
  submitted: string;
}

const STATUS_STYLE: Record<AppRow["status"], { color: string; label: string }> = {
  needs_info: { color: "#D97706", label: "Needs info" },
  approved: { color: "#2563EB", label: "Approved" },
};

const ROWS: AppRow[] = [
  { id: 1, status: "needs_info", customer: "Brandon Palmer", equipment: "Kubota SVL75-3 Track Loader · Dayton, TX", price: "$5,763", submitted: "8/5/2026" },
  { id: 2, status: "approved", customer: "Kirk Austin", equipment: "Bobcat E42 Mini Excavator · Bayton, TX", price: "$4,388", submitted: "8/3/2026" },
  { id: 3, status: "needs_info", customer: "Marisol Vega", equipment: "JLG 1930ES Scissor Lift · Conroe, TX", price: "$2,140", submitted: "8/2/2026" },
  { id: 4, status: "approved", customer: "Dell Rowan", equipment: "CAT 259D3 Skid Steer · Willis, TX", price: "$6,910", submitted: "7/29/2026" },
];

const COLUMNS: DataTableColumn<AppRow>[] = [
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
        <p className="text-xs text-neutral-400">{r.equipment}</p>
      </>
    ),
  },
  { key: "price", header: "Price", render: (r) => <span className="text-neutral-700">{r.price}</span> },
  { key: "submitted", header: "Submitted", render: (r) => <span className="text-neutral-500">{r.submitted}</span> },
  { key: "action", header: "", render: () => <InertLink className="text-sm">Review →</InertLink> },
];

export function ApplicationReviewPanel() {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <MetricCard value={3} label="Needs info" barColor="#D97706" barPercent={35} />
        <MetricCard value={0} label="Under review" barColor="#d4d4d4" barPercent={0} />
        <MetricCard value={6} label="Approved" barColor="#16A34A" barPercent={70} />
      </div>
      <DataTable
        title="Applications awaiting review"
        action={<InertLink className="font-heading text-xs uppercase tracking-wide">View all</InertLink>}
        columns={COLUMNS}
        rows={ROWS}
      />
    </div>
  );
}
