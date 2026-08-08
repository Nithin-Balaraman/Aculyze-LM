import type { LeadStatus, Priority } from "@/generated/prisma/enums";

const PRIORITY_STYLES: Record<Priority, string> = {
  High: "bg-red-100 text-red-700",
  Medium: "bg-amber-100 text-amber-700",
  Low: "bg-slate-100 text-slate-600",
};

export function PriorityBadge({ priority }: { priority: Priority }) {
  return (
    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${PRIORITY_STYLES[priority]}`}>
      {priority}
    </span>
  );
}

const STATUS_STYLES: Record<LeadStatus, string> = {
  New: "bg-blue-100 text-blue-700",
  Contacted: "bg-sky-100 text-sky-700",
  Qualified: "bg-indigo-100 text-indigo-700",
  Working: "bg-purple-100 text-purple-700",
  Nurture: "bg-teal-100 text-teal-700",
  Won: "bg-green-100 text-green-700",
  Lost: "bg-slate-200 text-slate-500",
  Unassigned: "bg-orange-100 text-orange-700",
};

export function StatusBadge({ status }: { status: LeadStatus }) {
  return (
    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[status]}`}>
      {status}
    </span>
  );
}
