"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";
import { useAuth } from "@/context/AuthContext";
import { logout } from "@/lib/auth";
import { listNotifications } from "@/lib/notifications";
import { useClickOutside } from "@/hooks/useClickOutside";
import { Avatar } from "@/components/account/Avatar";
import {
  BellIcon,
  BriefcaseIcon,
  CalculatorIcon,
  CheckCircleIcon,
  ChevronDownIcon,
  CreditCardIcon,
  DocumentIcon,
  HomeIcon,
  KeyIcon,
  LogOutIcon,
  MailIcon,
  MenuIcon,
  SettingsIcon,
  UserIcon,
  XIcon,
} from "@/components/icons";

const BASE_NAV_ITEMS = [{ label: "Dashboard", href: "/admin/dashboard", icon: HomeIcon }];

// Both require the application_review permission (or full/super-admin access)
// — same gate the backend puts on the applications and customers resources.
const APPLICATIONS_ITEM = { label: "My Applications", href: "/admin/applications", icon: DocumentIcon };
const CUSTOMERS_ITEM = { label: "Customer Accounts", href: "/admin/customers", icon: UserIcon };

// Every email the system sent (client, Joel, 2026-10-13); same application_review gate as customers.
const EMAIL_LOG_ITEM = { label: "Email Log", href: "/admin/email-log", icon: MailIcon };

// Quotes a mower's price with no customer attached; behind the same
// application_review gate as the backend's /admin/pricing/quote.
const CALCULATOR_ITEM = { label: "Calculator", href: "/admin/calculator", icon: CalculatorIcon };

// Requires the equipment_tracking permission (or full/super-admin access).
const EQUIPMENT_ITEM = { label: "Equipment", href: "/admin/equipment", icon: BriefcaseIcon };

// Requires the payment_tracking permission (or full/super-admin access).
const PAYMENTS_ITEM = { label: "Payments", href: "/admin/payments", icon: CreditCardIcon };

// Requires the contract_generation permission (or full/super-admin access).
const CONTRACTS_ITEM = { label: "Contracts", href: "/admin/contracts", icon: CheckCircleIcon };

// Managing admin accounts is super_admin only.
const SUPER_ADMIN_ONLY_ITEM = { label: "Admin Accounts", href: "/admin/admin-users", icon: SettingsIcon };

// Company-wide integrations (QuickBooks, etc.) — super_admin only, same as admin accounts.
const SETTINGS_ITEM = { label: "Settings", href: "/admin/settings", icon: KeyIcon };

/** One look for every desktop nav button, so the hidden row that measures them is exactly as wide as the real ones. */
function navButtonClass(active: boolean): string {
  return `group font-heading flex items-center gap-1.5 rounded-md border-b-[3px] px-2.5 py-2 text-xs font-semibold transition lg:px-3 lg:text-sm ${
    active
      ? "border-red-500 bg-red-950/60 text-white"
      : "border-transparent text-neutral-400 hover:border-red-500/60 hover:bg-red-950/30 hover:text-white"
  }`;
}

export function AdminTopNav() {
  const pathname = usePathname();
  const router = useRouter();
  const { user } = useAuth();
  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useClickOutside<HTMLDivElement>(useCallback(() => setMenuOpen(false), []));
  const [mobileOpen, setMobileOpen] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);
  const [moreOpen, setMoreOpen] = useState(false);
  const moreRef = useClickOutside<HTMLDivElement>(useCallback(() => setMoreOpen(false), []));
  // How many nav items fit next to the logo and the account menu; the rest go under "More". Starts at "all" and is measured
  // before the first paint, so a screen that is wide enough never shows a "More" button.
  const [visibleCount, setVisibleCount] = useState(Number.MAX_SAFE_INTEGER);
  const navBoxRef = useRef<HTMLDivElement>(null);
  const measureRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    listNotifications()
      .then((r) => setUnreadCount(r.unread_count))
      .catch(() => {});
  }, []);

  async function handleLogout() {
    await logout();
    router.push("/login");
  }

  const adminName = user?.name ?? "Admin";
  const isSuperAdmin = user?.role === "super_admin";
  const restrictions = user?.admin_permissions?.map((p) => p.permission) ?? [];
  const hasFullAccess = isSuperAdmin || restrictions.length === 0;
  const canSeeCustomers = hasFullAccess || restrictions.includes("application_review");
  const canSeeEquipment = hasFullAccess || restrictions.includes("equipment_tracking");
  const canSeePayments = hasFullAccess || restrictions.includes("payment_tracking");
  const canSeeContracts = hasFullAccess || restrictions.includes("contract_generation");

  const navItems = [
    ...BASE_NAV_ITEMS,
    // The sections used every day come first: when the window is too narrow for all of them, the last ones go under "More".
    ...(canSeeCustomers ? [APPLICATIONS_ITEM, CUSTOMERS_ITEM] : []),
    ...(canSeePayments ? [PAYMENTS_ITEM] : []),
    ...(canSeeContracts ? [CONTRACTS_ITEM] : []),
    ...(canSeeEquipment ? [EQUIPMENT_ITEM] : []),
    ...(canSeeCustomers ? [CALCULATOR_ITEM, EMAIL_LOG_ITEM] : []),
    ...(isSuperAdmin ? [SUPER_ADMIN_ONLY_ITEM, SETTINGS_ITEM] : []),
  ];
  const isActive = (href: string) => pathname === href || pathname.startsWith(`${href}/`);

  const itemCount = navItems.length;
  useLayoutEffect(() => {
    const box = navBoxRef.current;
    const row = measureRef.current;
    if (!box || !row) return;

    function fit() {
      if (!box || !row) return;
      const cells = Array.from(row.children) as HTMLElement[];
      const moreWidth = cells[cells.length - 1].offsetWidth;
      const widths = cells.slice(0, -1).map((cell) => cell.offsetWidth);
      const gap = parseFloat(getComputedStyle(row).columnGap) || 0;
      const available = box.clientWidth;

      const all = widths.reduce((sum, w) => sum + w, 0) + gap * Math.max(widths.length - 1, 0);
      if (all <= available) {
        setVisibleCount(widths.length);
        return;
      }
      let used = moreWidth;
      let count = 0;
      for (const w of widths) {
        if (used + gap + w > available) break;
        used += gap + w;
        count++;
      }
      setVisibleCount(count);
    }

    fit();
    // The box changes with the window; the row changes when the labels load in their final font.
    const observer = new ResizeObserver(fit);
    observer.observe(box);
    observer.observe(row);

    return () => observer.disconnect();
  }, [itemCount]);

  const shownItems = navItems.slice(0, visibleCount);
  const moreItems = navItems.slice(visibleCount);
  const moreActive = moreItems.some((item) => isActive(item.href));

  return (
    <header className="bg-neutral-950">
      <div className="grid grid-cols-[auto_1fr_auto] items-center gap-4  sm:px-8 lg:gap-6 lg:px-16 py-4 ">
        <Link href="/admin/dashboard">
          <Image
            src="/prostartLeasing.png"
            alt="Prostart Leasing"
            width={159}
            height={103}
            className="h-8 w-auto sm:h-11"
            priority
          />
        </Link>

        {/*
          Desktop nav, md and up (below md everything collapses into the hamburger). It shows as many sections as fit between the
          logo and the account menu and puts the rest under "More", so nothing is ever pushed off the screen.
        */}
        <div ref={navBoxRef} className="relative hidden min-w-0 md:block">
          <nav className="flex items-center justify-center gap-1 lg:gap-1.5">
            {shownItems.map((item) => {
              const active = isActive(item.href);
              const ItemIcon = item.icon;
              return (
                <Link key={item.href} href={item.href} className={navButtonClass(active)}>
                  <ItemIcon
                    className={`h-4 w-4 shrink-0 ${active ? "text-red-500" : "text-neutral-400 group-hover:text-red-500"}`}
                  />
                  <span className="whitespace-nowrap">{item.label}</span>
                </Link>
              );
            })}

            {moreItems.length > 0 && (
              <div className="relative" ref={moreRef}>
                <button
                  onClick={() => setMoreOpen((v) => !v)}
                  aria-haspopup="menu"
                  aria-expanded={moreOpen}
                  className={navButtonClass(moreActive)}
                >
                  <span className="whitespace-nowrap">More</span>
                  <ChevronDownIcon className={`h-3.5 w-3.5 shrink-0 ${moreActive ? "text-red-500" : "text-neutral-400 group-hover:text-red-500"}`} />
                </button>

                {moreOpen && (
                  <div role="menu" className="absolute right-0 top-full z-20 mt-1 w-52 rounded-md border border-neutral-200 bg-white py-1 shadow-lg">
                    {moreItems.map((item) => {
                      const active = isActive(item.href);
                      const ItemIcon = item.icon;
                      return (
                        <Link
                          key={item.href}
                          href={item.href}
                          role="menuitem"
                          onClick={() => setMoreOpen(false)}
                          className={`font-heading flex items-center gap-2 px-3 py-2 text-sm font-semibold hover:bg-neutral-50 ${
                            active ? "text-red-600" : "text-neutral-700"
                          }`}
                        >
                          <ItemIcon className={`h-4 w-4 ${active ? "text-red-500" : "text-neutral-400"}`} />
                          {item.label}
                        </Link>
                      );
                    })}
                  </div>
                )}
              </div>
            )}
          </nav>

          {/* Invisible copy of every section plus "More", only to measure how wide they are. Clipped to zero height so it can never widen the page. */}
          <div aria-hidden="true" className="pointer-events-none invisible absolute left-0 top-0 h-0 w-full overflow-hidden">
            <div ref={measureRef} className="flex w-max items-center gap-1 lg:gap-1.5">
              {navItems.map((item) => {
                const ItemIcon = item.icon;
                return (
                  <span key={item.href} className={navButtonClass(false)}>
                    <ItemIcon className="h-4 w-4 shrink-0" />
                    <span className="whitespace-nowrap">{item.label}</span>
                  </span>
                );
              })}
              <span className={navButtonClass(false)}>
                <span className="whitespace-nowrap">More</span>
                <ChevronDownIcon className="h-3.5 w-3.5 shrink-0" />
              </span>
            </div>
          </div>
        </div>

        <div className="flex items-center justify-self-end gap-2 sm:gap-3">
          <Link
            href="/admin/notifications"
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
              <Avatar name={adminName} url={user?.avatar_url} size={24} />
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
                <Link
                  href="/admin/account"
                  onClick={() => setMenuOpen(false)}
                  className="font-heading flex w-full items-center gap-2 px-3 py-2 text-left text-sm font-semibold text-neutral-700 hover:bg-neutral-50"
                >
                  <UserIcon className="h-4 w-4 text-neutral-400" />
                  My Account
                </Link>
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
          <Avatar name={adminName} url={user?.avatar_url} size={24} />
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

          <Link
            href="/admin/account"
            onClick={() => setMobileOpen(false)}
            className={`font-heading flex items-center gap-2 rounded-md border-b-[3px] px-3 py-2 text-sm font-semibold ${
              isActive("/admin/account")
                ? "border-red-500 bg-red-950/60 text-white"
                : "border-transparent text-neutral-400 hover:border-red-500/60 hover:bg-red-950/30 hover:text-white"
            }`}
          >
            <UserIcon className={`h-4 w-4 ${isActive("/admin/account") ? "text-red-500" : "text-neutral-500"}`} />
            My Account
          </Link>

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
