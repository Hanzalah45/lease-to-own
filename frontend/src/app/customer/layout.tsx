"use client";

import { DashboardShell, type NavItem } from "@/components/layout/DashboardShell";

const NAV_ITEMS: NavItem[] = [
  { label: "Dashboard", href: "/customer/dashboard" },
  { label: "Applications", href: "/customer/applications" },
  { label: "Lease agreement", href: "/customer/lease-agreements" },
  { label: "Contracts", href: "/customer/contracts" },
  { label: "Payments", href: "/customer/payments" },
];

export default function CustomerLayout({ children }: { children: React.ReactNode }) {
  return (
    <DashboardShell navItems={NAV_ITEMS} sectionLabel="Customer portal">
      {children}
    </DashboardShell>
  );
}
