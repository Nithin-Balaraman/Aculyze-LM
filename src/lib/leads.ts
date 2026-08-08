// Pure business logic for the Lead/Activity model — no database calls here.
// Anything that can drift out of sync (latest outcome, data-quality flags,
// "needs attention today") is computed from source data by these functions
// instead of being stored as a column, per the "live, never stale" design
// goal. src/lib/queries.ts wires these up to actual Prisma reads.

import { Activity, CallOutcome, Lead, LeadStatus, Priority } from "@/generated/prisma/client";

export const STALE_AFTER_DAYS = 7;
export const UNANSWERED_ESCALATION_THRESHOLD = 3;

export const UNANSWERED_OUTCOMES: CallOutcome[] = [
  CallOutcome.NoAnswer,
  CallOutcome.Unreachable,
];

export function isUnansweredOutcome(outcome: CallOutcome): boolean {
  return UNANSWERED_OUTCOMES.includes(outcome);
}

// A Lead is "archived" once it's won or lost — it drops out of every live
// working view but stays visible in the full Lead list / history.
export function isArchived(status: LeadStatus): boolean {
  return status === LeadStatus.Won || status === LeadStatus.Lost;
}

// actionStatus: Open unless the deal is decided.
export function actionStatus(status: LeadStatus): "Open" | "Closed" {
  return isArchived(status) ? "Closed" : "Open";
}

export function getLatestActivity<A extends Activity>(
  activitiesSortedDesc: A[]
): A | null {
  return activitiesSortedDesc[0] ?? null;
}

// A lead counts as "stale" (needing attention) once nobody has touched it
// in STALE_AFTER_DAYS days AND there's no explicit next action recorded —
// if a next action is set, the team already knows what's next, so it's not
// stale even if it's been a while.
export function isStale(
  lastActivityDate: Date | null,
  nextAction: string | null
): boolean {
  if (nextAction && nextAction.trim().length > 0) return false;
  if (!lastActivityDate) return true;
  const cutoff = new Date();
  cutoff.setDate(cutoff.getDate() - STALE_AFTER_DAYS);
  return lastActivityDate < cutoff;
}

export function isFollowUpDueToday(followUpDate: Date | null): boolean {
  if (!followUpDate) return false;
  const endOfToday = new Date();
  endOfToday.setHours(23, 59, 59, 999);
  return followUpDate <= endOfToday;
}

export function isFollowUpOverdue(followUpDate: Date | null): boolean {
  if (!followUpDate) return false;
  const startOfToday = new Date();
  startOfToday.setHours(0, 0, 0, 0);
  return followUpDate < startOfToday;
}

// The rule behind the home page: a lead needs attention today if it has a
// follow-up due (today or overdue), it's already High priority, or it's
// gone stale with nothing planned. Won/Lost leads never need attention.
export function needsAttentionToday(lead: {
  leadStatus: LeadStatus;
  priority: Priority;
  followUpDate: Date | null;
  nextAction: string | null;
}, lastActivityDate: Date | null): boolean {
  if (isArchived(lead.leadStatus)) return false;
  if (isFollowUpDueToday(lead.followUpDate)) return true;
  if (lead.priority === Priority.High) return true;
  if (isStale(lastActivityDate, lead.nextAction)) return true;
  return false;
}

// --- Data quality -----------------------------------------------------

export type DataQualityIssue =
  | "missing_contact_person"
  | "missing_phone_and_mobile"
  | "malformed_email"
  | "duplicate_company_name";

export const DATA_QUALITY_LABELS: Record<DataQualityIssue, string> = {
  missing_contact_person: "Missing contact person",
  missing_phone_and_mobile: "No phone or mobile number",
  malformed_email: "Malformed email address",
  duplicate_company_name: "Duplicate company name",
};

export function isMalformedEmail(email: string): boolean {
  return !email.includes("@") || !email.includes(".");
}

export function normalizeCompanyName(companyName: string): string {
  return companyName.trim().toLowerCase();
}

// Company names that appear on more than one Lead (case/whitespace
// insensitive) — computed over the whole table in one pass rather than a
// separate query per lead.
export function findDuplicateCompanyNames(
  leads: { companyName: string }[]
): Set<string> {
  const counts = new Map<string, number>();
  for (const lead of leads) {
    const key = normalizeCompanyName(lead.companyName);
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }
  const duplicates = new Set<string>();
  for (const [key, count] of counts) {
    if (count > 1) duplicates.add(key);
  }
  return duplicates;
}

export function getDataQualityIssues(
  lead: Pick<Lead, "companyName" | "contactPerson" | "contactNumber" | "mobileNumber" | "email">,
  duplicateNames: Set<string>
): DataQualityIssue[] {
  const issues: DataQualityIssue[] = [];
  if (!lead.contactPerson?.trim()) issues.push("missing_contact_person");
  if (!lead.contactNumber?.trim() && !lead.mobileNumber?.trim()) {
    issues.push("missing_phone_and_mobile");
  }
  if (lead.email?.trim() && isMalformedEmail(lead.email.trim())) {
    issues.push("malformed_email");
  }
  if (duplicateNames.has(normalizeCompanyName(lead.companyName))) {
    issues.push("duplicate_company_name");
  }
  return issues;
}
