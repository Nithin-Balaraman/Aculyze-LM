// Read-side database access. Every function here runs a fresh query — there
// is no caching layer, so whatever these return is guaranteed correct as of
// right now. See src/lib/leads.ts for the pure logic layered on top of the
// raw rows (latest-activity derivation, data-quality flags, etc.).
//
// This app is sized for a small team's real-world lead volume (dozens to a
// few thousand rows, not millions), so most views simply load the whole
// Lead table with its latest Activity attached and filter/sort in
// JavaScript. That's simpler to read and maintain than a bespoke SQL query
// per view — revisit if the table ever grows dramatically.

import { Activity, Lead, LeadStatus } from "@/generated/prisma/client";
import { prisma } from "@/lib/db";
import {
  actionStatus,
  DataQualityIssue,
  findDuplicateCompanyNames,
  getDataQualityIssues,
  getLatestActivity,
  isArchived,
  UNANSWERED_OUTCOMES,
} from "@/lib/leads";

export type LeadWithDerived = Lead & {
  latestActivity: Activity | null;
  latestCallOutcome: Activity["callOutcome"] | null;
  latestRemarks: string | null;
  lastActivityDate: Date | null;
  actionStatusValue: "Open" | "Closed";
  unansweredCount: number;
};

const leadWithDerivedInclude = {
  activities: { orderBy: { activityDate: "desc" as const }, take: 1 },
  _count: {
    select: {
      activities: { where: { callOutcome: { in: UNANSWERED_OUTCOMES } } },
    },
  },
};

function attachDerived(
  lead: Lead & { activities: Activity[]; _count: { activities: number } }
): LeadWithDerived {
  const { activities, _count, ...rest } = lead;
  const latestActivity = getLatestActivity(activities);
  return {
    ...rest,
    latestActivity,
    latestCallOutcome: latestActivity?.callOutcome ?? null,
    latestRemarks: latestActivity?.detailsRemarks ?? null,
    lastActivityDate: latestActivity?.activityDate ?? null,
    actionStatusValue: actionStatus(lead.leadStatus),
    unansweredCount: _count.activities,
  };
}

// Every Lead, with its latest Activity's outcome/remarks/date attached.
// Includes Won/Lost leads — callers filter those out with isArchived()
// where the spec calls for it (Today's Actions, Follow-ups, Unanswered,
// High-Priority), but the full Lead list and Data Quality view want them.
export async function getTotalActivityCount(): Promise<number> {
  return prisma.activity.count();
}

export async function getAllLeadsWithDerived(): Promise<LeadWithDerived[]> {
  const leads = await prisma.lead.findMany({
    orderBy: { companyName: "asc" },
    include: leadWithDerivedInclude,
  });
  return leads.map(attachDerived);
}

export function openLeadsOnly(leads: LeadWithDerived[]): LeadWithDerived[] {
  return leads.filter((lead) => !isArchived(lead.leadStatus));
}

export type LeadWithIssues = LeadWithDerived & { issues: DataQualityIssue[] };

// One row per lead with at least one data-quality problem, per the spec's
// "one row per lead with issues, not per issue" requirement.
export function withDataQualityIssues(leads: LeadWithDerived[]): LeadWithIssues[] {
  const duplicateNames = findDuplicateCompanyNames(leads);
  return leads
    .map((lead) => ({ ...lead, issues: getDataQualityIssues(lead, duplicateNames) }))
    .filter((lead) => lead.issues.length > 0);
}

export type LeadDetail = LeadWithDerived & { activities: Activity[] };

export async function getLeadById(id: number): Promise<LeadDetail | null> {
  const lead = await prisma.lead.findUnique({
    where: { id },
    include: { activities: { orderBy: { activityDate: "desc" } } },
  });
  if (!lead) return null;

  const unansweredCount = await prisma.activity.count({
    where: { leadId: id, callOutcome: { in: UNANSWERED_OUTCOMES } },
  });

  const { activities, ...rest } = lead;
  const latestActivity = getLatestActivity(activities);
  return {
    ...rest,
    activities,
    latestActivity,
    latestCallOutcome: latestActivity?.callOutcome ?? null,
    latestRemarks: latestActivity?.detailsRemarks ?? null,
    lastActivityDate: latestActivity?.activityDate ?? null,
    actionStatusValue: actionStatus(lead.leadStatus),
    unansweredCount,
  };
}

// Data-quality issues for a single lead, checked against every other
// company name in the table (for the duplicate-name flag).
export async function getDataQualityIssuesForLead(
  lead: Pick<Lead, "id" | "companyName" | "contactPerson" | "contactNumber" | "mobileNumber" | "email">
): Promise<DataQualityIssue[]> {
  const allNames = await prisma.lead.findMany({ select: { companyName: true } });
  const duplicateNames = findDuplicateCompanyNames(allNames);
  return getDataQualityIssues(lead, duplicateNames);
}

export type CompanySearchResult = Pick<
  Lead,
  | "id"
  | "leadId"
  | "companyName"
  | "industry"
  | "city"
  | "fullLocation"
  | "contactPerson"
  | "contactNumber"
  | "mobileNumber"
  | "email"
  | "leadStatus"
>;

// True type-ahead search for the "log an activity" form's company picker.
export async function searchLeadsByCompanyName(
  query: string,
  limit = 8
): Promise<CompanySearchResult[]> {
  const trimmed = query.trim();
  if (!trimmed) return [];
  return prisma.lead.findMany({
    where: { companyName: { contains: trimmed, mode: "insensitive" } },
    orderBy: { companyName: "asc" },
    take: limit,
    select: {
      id: true,
      leadId: true,
      companyName: true,
      industry: true,
      city: true,
      fullLocation: true,
      contactPerson: true,
      contactNumber: true,
      mobileNumber: true,
      email: true,
      leadStatus: true,
    },
  });
}

export async function getCompanySearchResultById(
  id: number
): Promise<CompanySearchResult | null> {
  return prisma.lead.findUnique({
    where: { id },
    select: {
      id: true,
      leadId: true,
      companyName: true,
      industry: true,
      city: true,
      fullLocation: true,
      contactPerson: true,
      contactNumber: true,
      mobileNumber: true,
      email: true,
      leadStatus: true,
    },
  });
}

// Case/whitespace-insensitive company name match, used to warn about
// duplicates before creating a new Lead.
export async function findLeadsByExactCompanyName(
  companyName: string
): Promise<Pick<Lead, "id" | "leadId" | "companyName" | "city" | "leadStatus">[]> {
  const trimmed = companyName.trim();
  if (!trimmed) return [];
  return prisma.lead.findMany({
    where: { companyName: { equals: trimmed, mode: "insensitive" } },
    select: { id: true, leadId: true, companyName: true, city: true, leadStatus: true },
  });
}

export function distinctAssignedOwners(leads: { assignedOwner: string | null }[]): string[] {
  const owners = new Set<string>();
  for (const lead of leads) {
    if (lead.assignedOwner?.trim()) owners.add(lead.assignedOwner.trim());
  }
  return [...owners].sort();
}

export const ARCHIVED_STATUSES: LeadStatus[] = [LeadStatus.Won, LeadStatus.Lost];
