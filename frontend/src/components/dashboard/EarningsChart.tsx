"use client";

import { useState } from "react";
import { ArrowUpRightIcon } from "@/components/icons";
import { SectionHeading } from "@/components/dashboard/SectionHeading";

export interface EarningsPoint {
  month: string;
  thisYear: number;
  lastYear: number;
}

const CHART_HEIGHT = 220;
const CHART_WIDTH = 900;
const GRIDLINES = 4;

export function EarningsChart({
  data,
  total,
  avgPerMonth,
  bestMonth,
}: {
  data: EarningsPoint[];
  total: string;
  avgPerMonth: string;
  bestMonth: { label: string; value: string };
}) {
  const [range, setRange] = useState<"6M" | "12M">("12M");
  const points = range === "6M" ? data.slice(-6) : data;
  const max = Math.max(...points.map((p) => Math.max(p.thisYear, p.lastYear)), 1);
  const niceMax = Math.ceil(max / 5000) * 5000 || 5000;

  const toXY = (value: number, index: number): [number, number] => {
    const x = points.length === 1 ? 0 : (index / (points.length - 1)) * CHART_WIDTH;
    const y = CHART_HEIGHT - (value / niceMax) * CHART_HEIGHT;
    return [x, y];
  };

  const linePath = (key: "thisYear" | "lastYear") =>
    points
      .map((p, i) => toXY(p[key], i))
      .map(([x, y], i) => `${i === 0 ? "M" : "L"}${x},${y}`)
      .join(" ");

  const areaPath = () => {
    const line = points.map((p, i) => toXY(p.thisYear, i));
    const [firstX] = line[0];
    const [lastX] = line[line.length - 1];
    return `M${firstX},${CHART_HEIGHT} ${line.map(([x, y]) => `L${x},${y}`).join(" ")} L${lastX},${CHART_HEIGHT} Z`;
  };

  const yLabels = Array.from({ length: GRIDLINES + 1 }, (_, i) => Math.round((niceMax / GRIDLINES) * (GRIDLINES - i)));

  return (
    <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white p-4 sm:p-5">
      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <SectionHeading
          title="Earnings overview"
          subtitle="Monthly revenue vs last year — hover the curve for detail."
        />
        <div className="flex items-center gap-3">
          <div className="flex items-center gap-3 text-xs font-semibold text-neutral-500">
            <span className="flex items-center gap-1.5">
              <span className="h-2 w-2 rounded-full bg-red-600" />
              This year
            </span>
            <span className="flex items-center gap-1.5">
              <span className="h-2 w-2 rounded-full bg-amber-400" />
              Last year
            </span>
          </div>
          <div className="flex rounded-md border border-neutral-200 p-0.5">
            {(["6M", "12M"] as const).map((r) => (
              <button
                key={r}
                onClick={() => setRange(r)}
                className={`font-heading rounded px-2.5 py-1 text-xs font-bold transition ${
                  range === r ? "bg-neutral-900 text-white" : "text-neutral-500 hover:text-neutral-800"
                }`}
              >
                {r}
              </button>
            ))}
          </div>
        </div>
      </div>

      <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <div style={{ minWidth: 560 }}>
          <div className="flex">
            <div
              className="flex flex-col justify-between pr-2 text-right text-[10px] text-neutral-400"
              style={{ height: CHART_HEIGHT }}
            >
              {yLabels.map((v) => (
                <span key={v}>${(v / 1000).toFixed(1)}k</span>
              ))}
            </div>
            <div className="relative flex-1">
              <svg
                viewBox={`0 0 ${CHART_WIDTH} ${CHART_HEIGHT}`}
                preserveAspectRatio="none"
                className="w-full"
                style={{ height: CHART_HEIGHT }}
              >
                {yLabels.map((_, i) => (
                  <line
                    key={i}
                    x1={0}
                    x2={CHART_WIDTH}
                    y1={(CHART_HEIGHT / GRIDLINES) * i}
                    y2={(CHART_HEIGHT / GRIDLINES) * i}
                    stroke="#e5e5e5"
                    strokeDasharray="4 4"
                  />
                ))}
                <defs>
                  <linearGradient id="earningsFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#dc2626" stopOpacity="0.25" />
                    <stop offset="100%" stopColor="#dc2626" stopOpacity="0" />
                  </linearGradient>
                </defs>
                <path d={areaPath()} fill="url(#earningsFill)" />
                <path d={linePath("lastYear")} fill="none" stroke="#f59e0b" strokeWidth={2} strokeDasharray="6 4" />
                <path d={linePath("thisYear")} fill="none" stroke="#dc2626" strokeWidth={2.5} />
              </svg>
            </div>
          </div>
          <div className="mt-2 flex pl-10 text-[10px] font-medium uppercase text-neutral-400">
            {points.map((p) => (
              <span key={p.month} className="flex-1 text-center">
                {p.month}
              </span>
            ))}
          </div>
        </div>
      </div>

      <div className="mt-6 grid grid-cols-1 gap-4 border-t border-neutral-100 pt-4 sm:grid-cols-3">
        <div>
          <p className="text-xs font-bold uppercase tracking-wide text-neutral-400">Total earnings</p>
          <p className="font-heading mt-1 text-xl font-black text-neutral-900">{total}</p>
        </div>
        <div>
          <p className="text-xs font-bold uppercase tracking-wide text-neutral-400">Avg / month</p>
          <p className="font-heading mt-1 text-xl font-black text-neutral-900">{avgPerMonth}</p>
        </div>
        <div>
          <p className="flex items-center gap-1 text-xs font-bold uppercase tracking-wide text-neutral-400">
            <ArrowUpRightIcon className="h-3 w-3 text-green-600" /> Best month
          </p>
          <p className="font-heading mt-1 text-xl font-black text-neutral-900">
            {bestMonth.label} <span className="text-red-600">{bestMonth.value}</span>
          </p>
        </div>
      </div>
    </div>
  );
}
