import {
  BriefcaseIcon,
  BuildingIcon,
  CreditCardIcon,
  DocumentIcon,
  LockIcon,
  ShieldIcon,
  StarIcon,
} from "@/components/icons";
import type { AdminPermissionKey } from "@/types/auth";

export type DashboardTabKey = "owner" | AdminPermissionKey;

const PERMISSION_TABS: { key: AdminPermissionKey; label: string; icon: typeof DocumentIcon }[] = [
  { key: "application_review", label: "Application Review", icon: DocumentIcon },
  { key: "risk_assessment", label: "Risk Assessment", icon: ShieldIcon },
  { key: "contract_generation", label: "Contract Generation", icon: BriefcaseIcon },
  { key: "equipment_tracking", label: "Equipment Tracking", icon: BuildingIcon },
  { key: "payment_tracking", label: "Payment Tracking", icon: CreditCardIcon },
];

/** Owner/role card plus the 5 permission areas — clicking a card switches the dashboard content below to that area's view. */
export function AccessTabs({
  ownerLabel,
  hasFullAccess,
  restrictions,
  activeKey,
  onSelect,
}: {
  ownerLabel: string;
  hasFullAccess: boolean;
  restrictions: AdminPermissionKey[];
  activeKey: DashboardTabKey;
  onSelect: (key: DashboardTabKey) => void;
}) {
  const ownerActive = activeKey === "owner";

  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
      <button
        type="button"
        onClick={() => onSelect("owner")}
        className={`font-heading flex flex-col items-center justify-center gap-1.5 rounded-xl px-4 py-4 transition ${
          ownerActive
            ? "bg-neutral-950 text-white"
            : "border border-neutral-200 bg-white text-neutral-700 hover:border-red-200 hover:bg-red-50/40"
        }`}
      >
        <StarIcon className="h-5 w-5 text-red-500" />
        <span className="text-xs font-bold uppercase tracking-wide">{ownerLabel}</span>
      </button>

      {PERMISSION_TABS.map((tab) => {
        const allowed = hasFullAccess || restrictions.includes(tab.key);
        const active = activeKey === tab.key;
        const TabIcon = tab.icon;

        if (!allowed) {
          return (
            <div
              key={tab.key}
              title="Restricted for this admin"
              className="flex cursor-not-allowed flex-col items-center justify-center gap-1.5 rounded-xl border border-neutral-100 bg-neutral-50 px-4 py-4"
            >
              <span className="flex h-8 w-8 items-center justify-center rounded-full bg-neutral-100 text-neutral-400">
                <LockIcon className="h-4 w-4" />
              </span>
              <span className="font-heading text-center text-xs font-bold uppercase leading-tight tracking-wide text-neutral-400">
                {tab.label}
              </span>
            </div>
          );
        }

        return (
          <button
            key={tab.key}
            type="button"
            onClick={() => onSelect(tab.key)}
            className={`flex flex-col items-center justify-center gap-1.5 rounded-xl px-4 py-4 transition ${
              active
                ? "bg-neutral-950"
                : "border border-neutral-200 bg-white hover:border-red-200 hover:bg-red-50/40"
            }`}
          >
            <span
              className={`flex h-8 w-8 items-center justify-center rounded-full ${
                active ? "bg-red-600 text-white" : "bg-red-50 text-red-600"
              }`}
            >
              <TabIcon className="h-4 w-4" />
            </span>
            <span
              className={`font-heading text-center text-xs font-bold uppercase leading-tight tracking-wide ${
                active ? "text-white" : "text-neutral-700"
              }`}
            >
              {tab.label}
            </span>
          </button>
        );
      })}
    </div>
  );
}
