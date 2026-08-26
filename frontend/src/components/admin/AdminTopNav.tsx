"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useCallback, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { logout } from "@/lib/auth";
import { useClickOutside } from "@/hooks/useClickOutside";
import {
  BellIcon,
  ChevronDownIcon,
  DocumentIcon,
  HomeIcon,
  LogOutIcon,
  MenuIcon,
  SettingsIcon,
  UserIcon,
  XIcon,
} from "@/components/icons";

const BASE_NAV_ITEMS = [
  { label: "Dashboard", href: "/admin/dashboard", icon: HomeIcon },
  { label: "My Applications", href: "/admin/applications", icon: DocumentIcon },
];

// Requires the application_review permission (or full/super-admin access).
const CUSTOMERS_ITEM = { label: "Customer Accounts", href: "/admin/customers", icon: UserIcon };

// Managing admin accounts is super_admin only.
const SUPER_ADMIN_ONLY_ITEM = { label: "Admin Accounts", href: "/admin/admin-users", icon: SettingsIcon };

export function AdminTopNav() {
  const pathname = usePathname();
  const router = useRouter();
  const { user } = useAuth();
  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useClickOutside<HTMLDivElement>(useCallback(() => setMenuOpen(false), []));
  const [mobileOpen, setMobileOpen] = useState(false);

  async function handleLogout() {
    await logout();
    router.push("/login");
  }

  const adminName = user?.name ?? "Admin";
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  const hasFullAccess = isSuperAdmin || restrictions.length === 0;
  const canSeeCustomers = hasFullAccess || restrictions.includes("application_review");

  const navItems = [
    ...BASE_NAV_ITEMS,
    ...(canSeeCustomers ? [CUSTOMERS_ITEM] : []),
    ...(isSuperAdmin ? [SUPER_ADMIN_ONLY_ITEM] : []),
  ];
  const isActive = (href: string) => pathname === href || pathname.startsWith(`${href}/`);

  return (
    <header className="bg-neutral-950">
      <div className="grid grid-cols-[auto_1fr_auto] items-center gap-4  sm:px-8 lg:gap-6 lg:px-16 py-4 ">
        <Link href="/admin/dashboard">
          <Image
            src="/prostartLeasing.png"
            alt="Outdoor Fix"
            width={159}
            height={103}
            className="h-8 w-auto sm:h-11"
            priority
          />
        </Link>

        {/* All sections visible directly on md+ screens; collapses into the hamburger below md. */}
        <nav className="hidden items-center justify-center gap-1 md:flex lg:gap-1.5">
          {navItems.map((item) => {
            const active = isActive(item.href);
            const ItemIcon = item.icon;
            return (
              <Link
                key={item.href}
                href={item.href}
                className={`group font-heading flex items-center gap-1.5 rounded-md border-b-[3px] px-2.5 py-2 text-xs font-semibold transition lg:px-3 lg:text-sm ${
                  active
                    ? "border-red-500 bg-red-950/60 text-white"
                    : "border-transparent text-neutral-400 hover:border-red-500/60 hover:bg-red-950/30 hover:text-white"
                }`}
              >
                <ItemIcon
                  className={`h-4 w-4 shrink-0 ${active ? "text-red-500" : "text-neutral-400 group-hover:text-red-500"}`}
                />
                <span className="whitespace-nowrap">{item.label}</span>
              </Link>
            );
          })}
        </nav>

        <div className="flex items-center justify-self-end gap-2 sm:gap-3">
          <button
            disabled
            title="Notifications are wired up once the notification system is built"
            className="relative flex items-center justify-center rounded-md border border-neutral-800 bg-neutral-900 p-2 text-neutral-300"
          >
            <BellIcon className="h-4 w-4" />
            <span className="font-heading absolute -right-1.5 -top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-red-600 text-[9px] font-bold text-white">
              0
            </span>
          </button>

          <div className="relative hidden md:block" ref={menuRef}>
            <button
              onClick={() => setMenuOpen((v) => !v)}
              className="font-heading flex items-center gap-2 rounded-md bg-neutral-900 px-2.5 py-2 text-sm font-semibold text-white hover:bg-neutral-800"
            >
              <span className="flex h-6 w-6 items-center justify-center rounded-full bg-red-600 text-[10px] font-bold text-white">
                {adminName.slice(0, 2).toUpperCase()}
              </span>
              <span className="flex flex-col items-start leading-tight">
                <span className="max-w-[10rem] truncate">{adminName}</span>
                {isSuperAdmin && (
                  <span className="text-[10px] font-bold uppercase tracking-wide text-red-500">Super admin</span>
                )}
              </span>
              <ChevronDownIcon className="h-3.5 w-3.5 shrink-0 text-neutral-400" />
            </button>

            {menuOpen && (
              <div className="absolute right-0 top-full z-10 mt-1 w-40 rounded-md border border-neutral-200 bg-white py-1 shadow-lg">
                <button
                  onClick={handleLogout}
                  className="font-heading flex w-full items-center gap-2 px-3 py-2 text-left text-sm font-semibold text-neutral-700 hover:bg-neutral-50"
                >
                  <LogOutIcon className="h-4 w-4 text-neutral-400" />
                  Log out
                </button>
              </div>
            )}
          </div>

          {/* Mobile hamburger — everything (nav + account menu) collapses in here below md */}
          <button
            onClick={() => setMobileOpen((v) => !v)}
            className="flex items-center justify-center rounded-md bg-neutral-900 p-2 text-white md:hidden"
          >
            {mobileOpen ? <XIcon className="h-5 w-5" /> : <MenuIcon className="h-5 w-5" />}
          </button>
        </div>
      </div>

      {mobileOpen && (
        <div className="space-y-1 border-t border-neutral-800 px-4 py-3 sm:px-8 md:hidden">
          <span className="flex h-6 w-6 items-center justify-center rounded-full bg-red-600 text-[10px] font-bold text-white">
            {adminName.slice(0, 2).toUpperCase()}
          </span>
          <p className="font-heading px-1 py-1 text-sm font-semibold text-white">
            {adminName}
            {isSuperAdmin && <span className="ml-2 text-[10px] uppercase tracking-wide text-red-500">Super admin</span>}
          </p>

          {navItems.map((item) => {
            const active = isActive(item.href);
            const ItemIcon = item.icon;
            return (
              <Link
                key={item.href}
                href={item.href}
                onClick={() => setMobileOpen(false)}
                className={`font-heading flex items-center gap-2 rounded-md border-b-[3px] px-3 py-2 text-sm font-semibold ${
                  active
                    ? "border-red-500 bg-red-950/60 text-white"
                    : "border-transparent text-neutral-400 hover:border-red-500/60 hover:bg-red-950/30 hover:text-white"
                }`}
              >
                <ItemIcon className={`h-4 w-4 ${active ? "text-red-500" : "text-neutral-500"}`} />
                {item.label}
              </Link>
            );
          })}

          <button
            onClick={handleLogout}
            className="font-heading flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-semibold text-neutral-400 hover:bg-neutral-900 hover:text-white"
          >
            <LogOutIcon className="h-4 w-4 text-neutral-500" />
            Log out
          </button>
        </div>
      )}
    </header>
  );
}
