"use client";

import Link from "next/link";
import { useCallback, useState, type ComponentType, type SVGProps } from "react";
import { DotsIcon, EyeIcon } from "@/components/icons";
import { useClickOutside } from "@/hooks/useClickOutside";

export function StatCard({
  label,
  value,
  note,
  noteTone = "neutral",
  icon: IconComponent,
  iconBg,
  iconColor,
  noteIcon: NoteIcon,
  viewHref,
}: {
  label: string;
  value: string;
  note: string;
  noteTone?: "positive" | "warning" | "neutral";
  icon: ComponentType<SVGProps<SVGSVGElement>>;
  iconBg: string;
  iconColor: string;
  noteIcon?: ComponentType<SVGProps<SVGSVGElement>>;
  viewHref?: string;
}) {
  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useClickOutside<HTMLDivElement>(useCallback(() => setMenuOpen(false), []));

  const noteColor = {
    positive: "text-green-600",
    warning: "text-amber-600",
    neutral: "text-neutral-500",
  }[noteTone];

  return (
    <div className="relative flex-1 p-5">
      <div className="mb-3 flex items-start justify-between">
        <span
          className="flex h-9 w-9 items-center justify-center rounded-full"
          style={{ backgroundColor: iconBg, color: iconColor }}
        >
          <IconComponent className="h-4 w-4" />
        </span>

        {viewHref && (
          <div className="relative" ref={menuRef}>
            <button
              onClick={() => setMenuOpen((v) => !v)}
              className={`rounded p-1 transition ${
                menuOpen ? "bg-neutral-100 text-neutral-700" : "text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700"
              }`}
            >
              <DotsIcon className="h-4 w-4" />
            </button>

            {menuOpen && (
              <div className="absolute right-0 top-full z-10 mt-1 w-32 rounded-md border border-neutral-200 bg-white py-1 shadow-lg">
                <Link
                  href={viewHref}
                  onClick={() => setMenuOpen(false)}
                  className="font-heading flex items-center gap-2 px-3 py-2 text-sm font-semibold text-neutral-700 hover:bg-neutral-50"
                >
                  <EyeIcon className="h-4 w-4 text-neutral-400" />
                  View
                </Link>
              </div>
            )}
          </div>
        )}
      </div>
      <p className="font-heading text-xs font-bold uppercase tracking-wide text-neutral-400">{label}</p>
      <p className="font-heading mt-1 text-4xl font-black text-neutral-900">{value}</p>
      <p className={`mt-1.5 flex items-center gap-1 text-xs font-semibold ${noteColor}`}>
        {NoteIcon && <NoteIcon className="h-3.5 w-3.5" />}
        {note}
      </p>
    </div>
  );
}
