// Pure presentation aggregation for dashboard charts — grouping/counting
// only, no business rules. Business logic (staleness, escalation, derived
// fields) lives in src/lib/leads.ts and is untouched by this file.

import { LeadStatus, Priority } from "@/generated/prisma/enums";

const STATUS_ORDER: LeadStatus[] = [
  LeadStatus.New,
  LeadStatus.Contacted,
  LeadStatus.Qualified,
  LeadStatus.Working,
  LeadStatus.Nurture,
  LeadStatus.Won,
  LeadStatus.Lost,
  LeadStatus.Unassigned,
];

const PRIORITY_ORDER: Priority[] = [Priority.High, Priority.Medium, Priority.Low];

export type StatusCount = { status: LeadStatus; count: number };
export type PriorityCount = { priority: Priority; count: number };

// Every status with at least one lead, in a fixed pipeline order — zero-count
// statuses are dropped so the donut chart doesn't render empty slices.
export function countByStatus(leads: { leadStatus: LeadStatus }[]): StatusCount[] {
  const counts = new Map<LeadStatus, number>();
  for (const lead of leads) {
    counts.set(lead.leadStatus, (counts.get(lead.leadStatus) ?? 0) + 1);
  }
  return STATUS_ORDER.map((status) => ({ status, count: counts.get(status) ?? 0 })).filter(
    (entry) => entry.count > 0
  );
}

// All three priorities always included (even at 0) so the bar chart's
// categories stay stable as data changes.
export function countByPriority(leads: { priority: Priority }[]): PriorityCount[] {
  const counts = new Map<Priority, number>();
  for (const lead of leads) {
    counts.set(lead.priority, (counts.get(lead.priority) ?? 0) + 1);
  }
  return PRIORITY_ORDER.map((priority) => ({ priority, count: counts.get(priority) ?? 0 }));
}
