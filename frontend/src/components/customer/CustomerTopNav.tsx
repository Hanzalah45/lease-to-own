"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { logout } from "@/lib/auth";
import { listNotifications } from "@/lib/notifications";
import { useClickOutside } from "@/hooks/useClickOutside";
import {
  BellIcon,
  ChevronDownIcon,
  DollarIcon,
  DocumentIcon,
  KeyIcon,
  LogOutIcon,
  MenuIcon,
  UserIcon,
  XIcon,
} from "@/components/icons";

const NAV_ITEMS = [
  { label: "My Lease", href: "/customer/dashboard", icon: KeyIcon },
  { label: "Payment", href: "/customer/payments", icon: DollarIcon },
  { label: "Contract", href: "/customer/contracts", icon: DocumentIcon },
  { label: "Account", href: "/customer/account", icon: UserIcon },
];

export function CustomerTopNav() {
  const pathname = usePathname();
  const router = useRouter();
  const { user } = useAuth();
  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useClickOutside<HTMLDivElement>(useCallback(() => setMenuOpen(false), []));
  const [mobileOpen, setMobileOpen] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);

  useEffect(() => {
    listNotifications()
      .then((r) => setUnreadCount(r.unread_count))
      .catch(() => {});
  }, []);

  async function handleLogout() {
    await logout();
    router.push("/login");
  }

  const name = user?.name ?? "Customer";
  const isActive = (href: string) => pathname === href || pathname.startsWith(`${href}/`);

  return (
    <header className="bg-neutral-950">
      <div className="grid grid-cols-[auto_1fr_auto] items-center gap-4 sm:px-8 lg:gap-6 lg:px-16 py-4">
        <Link href="/customer/dashboard">
          <Image
            src="/prostartLeasing.png"
            alt="Outdoor Fix"
            width={159}
            height={103}
            className="h-8 w-auto sm:h-11"
            priority
          />
        </Link>

        <nav className="hidden items-center justify-center gap-1 md:flex lg:gap-1.5">
          {NAV_ITEMS.map((item) => {
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
          <Link
            href="/customer/notifications"
            className="relative flex items-center justify-center rounded-md border border-neutral-800 bg-neutral-900 p-2 text-neutral-300 hover:text-white"
          >
            <BellIcon className="h-4 w-4" />
            {unreadCount > 0 && (
              <span className="font-heading absolute -right-1.5 -top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-red-600 text-[9px] font-bold text-white">
                {unreadCount > 9 ? "9+" : unreadCount}
              </span>
            )}
          </Link>

          <div className="relative hidden md:block" ref={menuRef}>
            <button
              onClick={() => setMenuOpen((v) => !v)}
              className="font-heading flex items-center gap-2 rounded-md bg-neutral-900 px-2.5 py-2 text-sm font-semibold text-white hover:bg-neutral-800"
            >
              <span className="flex h-6 w-6 items-center justify-center rounded-full bg-red-600 text-[10px] font-bold text-white">
                {name.slice(0, 2).toUpperCase()}
              </span>
              <span className="max-w-[10rem] truncate">{name}</span>
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
            {name.slice(0, 2).toUpperCase()}
          </span>
          <p className="font-heading px-1 py-1 text-sm font-semibold text-white">{name}</p>

          {NAV_ITEMS.map((item) => {
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
