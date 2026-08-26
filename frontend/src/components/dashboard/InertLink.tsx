import type { ReactNode } from "react";

/** A link-styled label for row actions that don't have a real destination yet (detail pages ship in a later milestone). */
export function InertLink({ children, className = "" }: { children: ReactNode; className?: string }) {
  return (
    <span
      title="Detail view is coming once this workflow's milestone is wired up"
      className={`cursor-not-allowed font-bold text-red-600/70 ${className}`}
    >
      {children}
    </span>
  );
}
