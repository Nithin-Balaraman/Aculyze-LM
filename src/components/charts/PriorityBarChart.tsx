"use client";

import { Bar, BarChart, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { PRIORITY_COLORS } from "@/lib/chart-colors";
import type { PriorityCount } from "@/lib/chart-data";

export function PriorityBarChart({ data }: { data: PriorityCount[] }) {
  const chartData = data.map((d) => ({ name: d.priority, value: d.count }));

  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart data={chartData} margin={{ top: 8, right: 8, left: -16, bottom: 0 }}>
        <XAxis dataKey="name" tick={{ fontSize: 12 }} />
        <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
        <Tooltip />
        <Bar dataKey="value" radius={[4, 4, 0, 0]}>
          {chartData.map((entry) => (
            <Cell key={entry.name} fill={PRIORITY_COLORS[entry.name as keyof typeof PRIORITY_COLORS]} />
          ))}
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  );
}
