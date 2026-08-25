"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useCallback, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { logout } from "@/lib/auth";
import { useClickOutside } from "@/hooks/useClickOutside";
import {
  AlertCircleIcon,
  BriefcaseIcon,
  BuildingIcon,
  ChevronDownIcon,
  CreditCardIcon,
  DocumentIcon,
  HomeIcon,
  LogOutIcon,
  MenuIcon,
  PlusIcon,
  UserIcon,
  XIcon,
} from "@/components/icons";

const NAV_ITEMS = [
  { label: "Dashboard", href: "/admin/dashboard", icon: HomeIcon },
  { label: "My Applications", href: "/admin/applications", icon: DocumentIcon },
];

const MENU_ITEMS = [
  { label: "Risk profiles", href: "/admin/risk-profiles", icon: AlertCircleIcon },
  { label: "Equipment", href: "/admin/equipment", icon: BuildingIcon },
  { label: "Contracts", href: "/admin/contracts", icon: BriefcaseIcon },
  { label: "Payments", href: "/admin/payments", icon: CreditCardIcon },
  { label: "Admin users", href: "/admin/admin-users", icon: UserIcon },
];

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
  const isActive = (href: string) => pathname === href || pathname.startsWith(`${href}/`);

  return (
    <header className="bg-neutral-950">
      <div className="grid grid-cols-[auto_1fr_auto] items-center gap-4  sm:px-8 lg:gap-6 lg:px-16 py-4 ">
        <Image
          src="/prostartLeasing.png"
          alt="Outdoor Fix"
          width={159}
          height={103}
          className="h-8 w-auto sm:h-11"
          priority
        />

        <nav className="hidden items-center justify-center gap-2 md:flex">
          {NAV_ITEMS.map((item) => {
            const active = isActive(item.href);
            const ItemIcon = item.icon;
            return (
              <Link
                key={item.href}
                href={item.href}
                className={`group font-heading flex items-center gap-1.5 rounded-md border-b-[3px] px-4 py-2 text-sm font-semibold transition ${active
                  ? "border-red-500 bg-red-950/60 text-white"
                  : "border-transparent text-neutral-400 hover:border-red-500/60 hover:bg-red-950/30 hover:text-white"
                  }`}
              >
                <ItemIcon
                  className={`h-4 w-4 ${active ? "text-red-500" : "text-neutral-400 group-hover:text-red-500"}`}
                />
                {item.label}
              </Link>
            );
          })}
        </nav>

        <div className="flex items-center justify-self-end gap-2 sm:gap-3">
          <button
            disabled
            title="Coming once the application workflow (Milestone 5) is wired up"
            className="font-heading hidden items-center gap-1.5 rounded-md bg-gradient-to-b from-red-500 to-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm shadow-red-900/30 sm:flex"
          >
            <PlusIcon className="h-4 w-4" />
            New Application
          </button>
          <button
            disabled
            title="Coming once the application workflow (Milestone 5) is wired up"
            className="flex items-center rounded-md bg-gradient-to-b from-red-500 to-red-600 p-2 text-white shadow-sm shadow-red-900/30 sm:hidden"
          >
            <PlusIcon className="h-4 w-4" />
          </button>

          <div className="relative hidden md:block" ref={menuRef}>
            <button
              onClick={() => setMenuOpen((v) => !v)}
              className="font-heading flex items-center gap-2 rounded-md bg-neutral-900 px-2.5 py-2 text-sm font-semibold text-white hover:bg-neutral-800"
            >
              <span className="flex h-6 w-6 items-center justify-center rounded-full bg-red-600 text-[10px] font-bold text-white">
                {adminName.slice(0, 2).toUpperCase()}
              </span>
              <span className="max-w-[10rem] truncate">{adminName}</span>
              <ChevronDownIcon className="h-3.5 w-3.5 shrink-0 text-neutral-400" />
            </button>

            {menuOpen && (
              <div className="absolute right-0 top-full z-10 mt-1 w-52 rounded-md border border-neutral-200 bg-white py-1 shadow-lg">
                {MENU_ITEMS.map((item) => {
                  const ItemIcon = item.icon;
                  return (
                    <Link
                      key={item.href}
                      href={item.href}
                      onClick={() => setMenuOpen(false)}
                      className="font-heading flex items-center gap-2 px-3 py-2 text-sm font-semibold text-neutral-700 hover:bg-neutral-50"
                    >
                      <ItemIcon className="h-4 w-4 text-neutral-400" />
                      {item.label}
                    </Link>
                  );
                })}
                <div className="my-1 border-t border-neutral-100" />
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
          <p className="font-heading px-1 py-1 text-sm font-semibold text-white">{adminName}</p>

          {[...NAV_ITEMS, ...MENU_ITEMS].map((item) => {
            const active = isActive(item.href);
            const ItemIcon = item.icon;
            return (
              <Link
                key={item.href}
                href={item.href}
                onClick={() => setMobileOpen(false)}
                className={`font-heading flex items-center gap-2 rounded-md border-b-[3px] px-3 py-2 text-sm font-semibold ${active
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
