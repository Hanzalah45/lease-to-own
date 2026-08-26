"use client";

import { useEffect, type ReactNode } from "react";
import { XIcon } from "@/components/icons";

export function Modal({
  title,
  onClose,
  children,
  maxWidthClassName = "max-w-md",
}: {
  title: string;
  onClose: () => void;
  children: ReactNode;
  maxWidthClassName?: string;
}) {
  useEffect(() => {
    function handleKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    document.addEventListener("keydown", handleKey);
    return () => document.removeEventListener("keydown", handleKey);
  }, [onClose]);

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-neutral-950/60 p-4"
      onClick={onClose}
    >
      <div
        className={`w-full ${maxWidthClassName} rounded-xl bg-white shadow-2xl`}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between border-b border-neutral-100 px-5 py-4">
          <div className="flex items-center gap-2">
            <span className="h-4 w-1 shrink-0 rounded-full bg-red-600" />
            <h2 className="text-lg font-bold uppercase tracking-wide text-neutral-900">{title}</h2>
          </div>
          <button
            onClick={onClose}
            className="rounded p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700"
          >
            <XIcon className="h-4 w-4" />
          </button>
        </div>
        <div className="p-5">{children}</div>
      </div>
    </div>
  );
}
