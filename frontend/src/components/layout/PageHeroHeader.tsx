import type { ReactNode } from "react";

/** Shared gradient header band used at the top of admin list pages (Applications, Customers, Dashboard). */
export function PageHeroHeader({
  title,
  subtitle,
  action,
  children,
}: {
  title: string;
  subtitle: string;
  action?: ReactNode;
  children?: ReactNode;
}) {
  return (
    <div
      className="-mx-4 -mt-4 space-y-6 px-4 pb-6 pt-4 sm:-mx-8 sm:-mt-6 sm:px-8 sm:pt-6 lg:-mx-16 lg:px-16"
      style={{
        background:
          "linear-gradient(to left, rgba(220,38,38,0.24) 0%, rgba(220,38,38,0.10) 35%, transparent 65%)",
      }}
    >
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-3xl font-black uppercase tracking-tight text-neutral-900 sm:text-4xl">{title}</h1>
          <p className="text-sm text-neutral-400">{subtitle}</p>
        </div>
        {action}
      </div>
      {children}
    </div>
  );
}
