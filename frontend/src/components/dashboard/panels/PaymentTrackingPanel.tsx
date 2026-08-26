import { DataTable, type DataTableColumn } from "@/components/dashboard/DataTable";
import { EarningsChart, type EarningsPoint } from "@/components/dashboard/EarningsChart";
import { FundedVolumeChart, type WeekPoint } from "@/components/dashboard/FundedVolumeChart";
import { InertLink } from "@/components/dashboard/InertLink";
import { StatCard } from "@/components/dashboard/StatCard";
import { StatusTag } from "@/components/dashboard/StatusTag";
import { AlertCircleIcon, ArrowUpRightIcon, CheckCircleIcon, ClockIcon, CreditCardIcon } from "@/components/icons";

interface PaymentRow {
  id: number;
  status: "overdue" | "processing" | "autopay";
  customer: string;
  location: string;
  amount: string;
  due: string;
}

const STATUS_STYLE: Record<PaymentRow["status"], { color: string; label: string }> = {
  overdue: { color: "#DC2626", label: "Overdue" },
  processing: { color: "#7C3AED", label: "Processing" },
  autopay: { color: "#16A34A", label: "Autopay set" },
};

const ROWS: PaymentRow[] = [
  { id: 1, status: "overdue", customer: "Brandon Palmer", location: "Dayton, TX", amount: "$328.03", due: "8/1/2026" },
  { id: 2, status: "processing", customer: "Kirk Austin", location: "Bayton, TX", amount: "$404.52", due: "8/22/2026" },
  { id: 3, status: "autopay", customer: "Brett Deyo", location: "Wylie, TX", amount: "$404.52", due: "9/18/2026" },
];

const COLUMNS: DataTableColumn<PaymentRow>[] = [
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
  { key: "amount", header: "Amount", render: (r) => <span className="text-neutral-700">{r.amount}</span> },
  { key: "due", header: "Due", render: (r) => <span className="text-neutral-500">{r.due}</span> },
  {
    key: "action",
    header: "",
    render: (r) => <InertLink className="text-sm">{r.status === "autopay" ? "View →" : "Collect →"}</InertLink>,
  },
];

const EARNINGS_DATA: EarningsPoint[] = [
  { month: "Sep", thisYear: 8600, lastYear: 6800 },
  { month: "Oct", thisYear: 9600, lastYear: 7400 },
  { month: "Nov", thisYear: 9200, lastYear: 7900 },
  { month: "Dec", thisYear: 11800, lastYear: 8600 },
  { month: "Jan", thisYear: 14700, lastYear: 9100 },
  { month: "Feb", thisYear: 13100, lastYear: 9600 },
  { month: "Mar", thisYear: 12100, lastYear: 10400 },
  { month: "Apr", thisYear: 13300, lastYear: 11000 },
  { month: "May", thisYear: 16600, lastYear: 11700 },
  { month: "Jun", thisYear: 18200, lastYear: 12600 },
  { month: "Jul", thisYear: 16400, lastYear: 12100 },
  { month: "Aug", thisYear: 15100, lastYear: 11800 },
];

const COLLECTIONS_DATA: WeekPoint[] = [
  { label: "Wk 1", units: 3 },
  { label: "Wk 2", units: 2 },
  { label: "Wk 3", units: 1 },
  { label: "Wk 4", units: 2 },
  { label: "Wk 5", units: 3 },
  { label: "Wk 6", units: 1 },
  { label: "Wk 7", units: 2 },
  { label: "Wk 8", units: 3 },
];

export function PaymentTrackingPanel() {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 divide-y divide-neutral-200 rounded-xl border border-neutral-200 bg-white sm:grid-cols-2 sm:divide-x sm:divide-y-0 lg:grid-cols-4">
        <StatCard
          label="Collected this month"
          value="$14.2k"
          note="+8.4% vs last month"
          noteTone="positive"
          icon={CreditCardIcon}
          iconBg="#DCFCE7"
          iconColor="#16A34A"
          noteIcon={ArrowUpRightIcon}
        />
        <StatCard
          label="Overdue"
          value="2"
          note="+33% vs last month"
          noteTone="warning"
          icon={AlertCircleIcon}
          iconBg="#FEF3C7"
          iconColor="#D97706"
          noteIcon={ClockIcon}
        />
        <StatCard
          label="Autopay enrolled"
          value="67%"
          note="+12% vs last month"
          noteTone="positive"
          icon={CheckCircleIcon}
          iconBg="#FCE7EE"
          iconColor="#DC2626"
          noteIcon={ArrowUpRightIcon}
        />
        <StatCard
          label="Avg. monthly payment"
          value="$328"
          note="+5.1% vs last month"
          noteTone="positive"
          icon={CreditCardIcon}
          iconBg="#DBEAFE"
          iconColor="#2563EB"
          noteIcon={ArrowUpRightIcon}
        />
      </div>

      <EarningsChart data={EARNINGS_DATA} total="$153.4k" avgPerMonth="$12.8k" bestMonth={{ label: "Jun", value: "$18.2k" }} />

      <FundedVolumeChart data={COLLECTIONS_DATA} title="Collections" />

      <DataTable title="Overdue & upcoming payments" columns={COLUMNS} rows={ROWS} />
    </div>
  );
}
