"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import type { ReactNode } from "react";
import { useAuth } from "@/context/AuthContext";
import { logout } from "@/lib/auth";

export interface NavItem {
  label: string;
  href: string;
}

export function DashboardShell({
  navItems,
  sectionLabel,
  children,
}: {
  navItems: NavItem[];
  sectionLabel: string;
  children: ReactNode;
}) {
  const pathname = usePathname();
  const router = useRouter();
  const { user } = useAuth();

  async function handleLogout() {
    await logout();
    router.push("/login");
  }

  return (
    <div className="flex flex-1">
      <aside className="w-60 shrink-0 border-r border-neutral-200 p-4">
        <p className="font-heading mb-4 text-sm font-bold uppercase tracking-wide text-neutral-400">
          {sectionLabel}
        </p>
        <nav className="space-y-1">
          {navItems.map((item) => {
            const active = pathname === item.href || pathname.startsWith(`${item.href}/`);
            return (
              <Link
                key={item.href}
                href={item.href}
                className={`font-heading block rounded-md px-3 py-2 text-sm font-semibold uppercase tracking-wide ${
                  active ? "bg-neutral-900 text-white" : "text-neutral-700 hover:bg-neutral-100"
                }`}
              >
                {item.label}
              </Link>
            );
          })}
        </nav>
      </aside>

      <div className="flex flex-1 flex-col">
        <header className="flex items-center justify-between border-b border-neutral-200 px-6 py-3">
          <span className="text-sm text-neutral-500">{user?.name}</span>
          <button onClick={handleLogout} className="font-heading text-sm font-bold text-neutral-700 hover:underline">
            Log out
          </button>
        </header>
        <main className="flex-1 p-6">{children}</main>
      </div>
    </div>
  );
}
