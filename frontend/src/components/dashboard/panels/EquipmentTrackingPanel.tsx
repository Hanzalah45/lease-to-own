import { DataTable, type DataTableColumn } from "@/components/dashboard/DataTable";
import { MetricCard } from "@/components/dashboard/MetricCard";

interface EquipmentRow {
  id: number;
  unit: string;
  serial: string;
  customer: string;
  condition: string;
  delivered: string;
  muted?: boolean;
}

const ROWS: EquipmentRow[] = [
  { id: 1, unit: "Worldlawn Diamondback 60\"", serial: "AGZ3WA18973", customer: "Robert Kirkland", condition: "New / 2026", delivered: "7/22/2026" },
  { id: 2, unit: "Worldlawn Gator 34\"", serial: "202303U13213", customer: "Loyd Ellis", condition: "New / 2026", delivered: "7/20/2026" },
  { id: 3, unit: "Ferris IS3200Z", serial: "FRS220091", customer: "Cindy Robles", condition: "New / 2026", delivered: "7/18/2026" },
  { id: 4, unit: "Scag Turf Tiger", serial: "STT550112", customer: "— In stock —", condition: "Used / 2024", delivered: "—", muted: true },
];

const COLUMNS: DataTableColumn<EquipmentRow>[] = [
  { key: "unit", header: "Unit", render: (r) => <span className={r.muted ? "text-neutral-400" : "font-medium text-neutral-900"}>{r.unit}</span> },
  { key: "serial", header: "Serial #", render: (r) => <span className={r.muted ? "text-neutral-400" : "text-neutral-700"}>{r.serial}</span> },
  { key: "customer", header: "Customer", render: (r) => <span className={r.muted ? "text-neutral-400" : "text-neutral-700"}>{r.customer}</span> },
  { key: "condition", header: "Condition", render: (r) => <span className={r.muted ? "text-neutral-400" : "text-neutral-500"}>{r.condition}</span> },
  { key: "delivered", header: "Delivered", render: (r) => <span className={r.muted ? "text-neutral-400" : "text-neutral-500"}>{r.delivered}</span> },
];

export function EquipmentTrackingPanel() {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <MetricCard value={41} label="Units leased" barColor="#171717" barPercent={90} />
        <MetricCard value={6} label="Available / in stock" barColor="#16A34A" barPercent={40} />
        <MetricCard value={1} label="Service due" barColor="#D97706" barPercent={10} />
      </div>
      <DataTable title="Equipment units" columns={COLUMNS} rows={ROWS} />
    </div>
  );
}
