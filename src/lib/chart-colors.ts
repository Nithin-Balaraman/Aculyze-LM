import { LeadStatus, Priority } from "@/generated/prisma/enums";

// Hex equivalents of the brand palette + the semantic status/priority
// colors used elsewhere (Badges.tsx), for use in recharts fills — SVG
// charts need real color values, not Tailwind class names.

export const BRAND_NAVY = "#0F3460";
export const BRAND_CYAN = "#1E88E5";
export const BRAND_CYAN_LIGHT = "#29ABE2";

export const STATUS_COLORS: Record<LeadStatus, string> = {
  New: "#3B82F6", // blue-500
  Contacted: "#0EA5E9", // sky-500
  Qualified: "#6366F1", // indigo-500
  Working: "#A855F7", // purple-500
  Nurture: "#14B8A6", // teal-500
  Won: "#22C55E", // green-500 — semantic, matches StatusBadge
  Lost: "#F43F5E", // rose-500 — semantic, matches StatusBadge
  Unassigned: "#F97316", // orange-500
};

export const PRIORITY_COLORS: Record<Priority, string> = {
  High: "#DC2626", // red-600 — semantic, matches PriorityBadge
  Medium: "#D97706", // amber-600 — semantic, matches PriorityBadge
  Low: "#64748B", // slate-500
};
