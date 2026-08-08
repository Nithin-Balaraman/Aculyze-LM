"use client";

import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { BRAND_CYAN } from "@/lib/chart-colors";
import type { DailyActivityCount } from "@/lib/queries";

function formatShortDate(isoDate: string): string {
  return new Date(isoDate).toLocaleDateString("en-IN", { day: "2-digit", month: "short" });
}

export function ActivityTrendChart({ data }: { data: DailyActivityCount[] }) {
  const chartData = data.map((d) => ({ date: formatShortDate(d.date), count: d.count }));
  // Thin out x-axis labels so ~30 days of ticks don't overlap.
  const tickInterval = Math.max(0, Math.ceil(chartData.length / 8) - 1);

  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart data={chartData} margin={{ top: 8, right: 8, left: -16, bottom: 0 }}>
        <XAxis dataKey="date" tick={{ fontSize: 11 }} interval={tickInterval} />
        <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
        <Tooltip />
        <Bar dataKey="count" fill={BRAND_CYAN} radius={[2, 2, 0, 0]} />
      </BarChart>
    </ResponsiveContainer>
  );
}
