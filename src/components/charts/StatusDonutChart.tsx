"use client";

import { Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip } from "recharts";
import { STATUS_COLORS } from "@/lib/chart-colors";
import type { StatusCount } from "@/lib/chart-data";

export function StatusDonutChart({ data }: { data: StatusCount[] }) {
  if (data.length === 0) {
    return <p className="flex h-full items-center justify-center text-sm text-slate-400">No leads yet.</p>;
  }

  const chartData = data.map((d) => ({ name: d.status, value: d.count }));

  return (
    <ResponsiveContainer width="100%" height="100%">
      <PieChart>
        <Pie data={chartData} dataKey="value" nameKey="name" innerRadius="55%" outerRadius="85%">
          {chartData.map((entry) => (
            <Cell key={entry.name} fill={STATUS_COLORS[entry.name as keyof typeof STATUS_COLORS]} />
          ))}
        </Pie>
        <Tooltip />
        <Legend wrapperStyle={{ fontSize: 12 }} />
      </PieChart>
    </ResponsiveContainer>
  );
}
