"use client";

import { useState } from "react";
import { ArrowUpRightIcon } from "@/components/icons";
import { SectionHeading } from "@/components/dashboard/SectionHeading";

export interface WeekPoint {
  label: string;
  units: number;
  /** Current/in-progress week: rendered as an outline bar with a target badge instead of a filled one. */
  current?: boolean;
  target?: number;
}

const CHART_HEIGHT_PX = 160;
const GRIDLINES = 4;

export function FundedVolumeChart({
  data,
  total,
  growthLabel,
}: {
  data: WeekPoint[];
  total?: number;
  growthLabel?: string;
}) {
  const [hovered, setHovered] = useState<number | null>(null);
  const max = Math.max(...data.map((d) => d.target ?? d.units), 1);

  return (
    <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
      <div className="mb-8 flex flex-col gap-3 sm:mb-10 sm:flex-row sm:items-start sm:justify-between">
        <SectionHeading title="Funded volume" subtitle={`Last ${data.length} weeks · units funded`} />
        {total !== undefined && (
          <div className="text-left sm:whitespace-nowrap sm:text-right">
            <p className="font-heading text-2xl font-black text-neutral-900">{total}</p>
            {growthLabel && (
              <p className="flex items-center gap-1 text-xs font-semibold text-green-600 sm:justify-end">
                <ArrowUpRightIcon className="h-3.5 w-3.5 shrink-0" />
                {growthLabel}
              </p>
            )}
          </div>
        )}
      </div>

      <div className="-mx-4 overflow-x-auto px-4 pt-6 sm:mx-0 sm:overflow-visible sm:px-0 sm:pt-0">
      <div
        className="relative flex items-end gap-3"
        style={{ height: CHART_HEIGHT_PX, minWidth: data.length * 48 }}
      >
        {/* Gridlines */}
        <div className="pointer-events-none absolute inset-0 flex flex-col justify-between">
          {Array.from({ length: GRIDLINES + 1 }).map((_, i) => (
            <div key={i} className="border-t border-dashed border-neutral-200" />
          ))}
        </div>

        {data.map((point, index) => {
          const isHovered = hovered === index;
          const barValue = point.current ? (point.target ?? point.units) : point.units;
          const barHeight = Math.max((barValue / max) * CHART_HEIGHT_PX, 6);

          return (
            <div
              key={point.label}
              className="relative flex flex-1 flex-col items-center justify-end gap-2 self-stretch"
              onMouseEnter={() => setHovered(index)}
              onMouseLeave={() => setHovered(null)}
            >
              {isHovered && (
                <div
                  className="font-heading absolute z-10 whitespace-nowrap rounded-md bg-neutral-900 px-2.5 py-1.5 text-xs text-white"
                  style={{ bottom: barHeight + 12 }}
                >
                  <p className="font-bold">{point.label.toUpperCase()}</p>
                  <p className="text-neutral-300">Funded: {point.units} Unit</p>
                </div>
              )}

              {point.current && point.target !== undefined && (
                <span className="font-heading absolute -top-6 whitespace-nowrap rounded-full border border-red-600 bg-white px-2 py-0.5 text-[10px] font-bold text-red-600">
                  {point.target} units
                </span>
              )}

              {isHovered && <div className="absolute inset-0 -mx-1 rounded-md bg-red-50" />}

              <div
                className={`relative z-[1] w-9 rounded-t ${
                  point.current
                    ? "border border-red-300 bg-white"
                    : "bg-gradient-to-t from-red-600 to-red-400"
                }`}
                style={{ height: barHeight }}
              />
              <span className="relative z-[1] text-[10px] font-medium uppercase text-neutral-400">
                {point.label}
              </span>
            </div>
          );
        })}
      </div>
      </div>
    </div>
  );
}
