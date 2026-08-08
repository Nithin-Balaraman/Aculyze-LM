"use server";

import { redirect } from "next/navigation";
import { revalidatePath } from "next/cache";
import {
  ActivityType,
  CallOutcome,
  ContactChannel,
  LeadStatus,
  Prisma,
  Priority,
} from "@/generated/prisma/client";
import { requireUser } from "@/lib/auth";
import { prisma } from "@/lib/db";
import { optionalDate, optionalString } from "@/lib/form-helpers";
import { formatActivityId } from "@/lib/ids";
import {
  isUnansweredOutcome,
  UNANSWERED_ESCALATION_THRESHOLD,
  UNANSWERED_OUTCOMES,
} from "@/lib/leads";

export type CreateActivityState = { error?: string };

function revalidateActivityViews(leadDbId: number) {
  revalidatePath("/");
  revalidatePath("/leads");
  revalidatePath(`/leads/${leadDbId}`);
  revalidatePath("/follow-ups");
  revalidatePath("/appointments");
  revalidatePath("/unanswered");
  revalidatePath("/high-priority");
  revalidatePath("/data-quality");
}

export async function createActivityAction(
  _prevState: CreateActivityState,
  formData: FormData
): Promise<CreateActivityState> {
  const user = await requireUser();

  const leadDbId = Number(formData.get("leadDbId"));
  if (!leadDbId) return { error: "Search for and select a company first." };

  const lead = await prisma.lead.findUnique({ where: { id: leadDbId } });
  if (!lead) return { error: "That lead no longer exists." };

  const activityType = optionalString(formData, "activityType") as ActivityType | null;
  const contactChannel = optionalString(formData, "contactChannel") as ContactChannel | null;
  const callOutcome = optionalString(formData, "callOutcome") as CallOutcome | null;
  const leadStatus = optionalString(formData, "leadStatus") as LeadStatus | null;

  if (!activityType || !contactChannel || !callOutcome || !leadStatus) {
    return { error: "Activity type, contact channel, outcome, and status are all required." };
  }

  const detailsRemarks = optionalString(formData, "detailsRemarks");
  const nextAction = optionalString(formData, "nextAction");
  const followUpDate = optionalDate(formData, "followUpDate");
  const assignedOwner = optionalString(formData, "assignedOwner") ?? user.name;

  await prisma.$transaction(async (tx) => {
    const created = await tx.activity.create({
      data: {
        activityId: "PENDING",
        leadId: leadDbId,
        activityType,
        contactChannel,
        callOutcome,
        detailsRemarks,
        nextAction,
        followUpDate,
        leadStatus,
        assignedOwner,
        sourceSheet: lead.sourceSheet,
      },
    });
    await tx.activity.update({
      where: { id: created.id },
      data: { activityId: formatActivityId(created.id) },
    });

    // Live-update the parent Lead. leadStatus always follows the newest
    // activity. nextAction/followUpDate only move if this activity actually
    // set them — an activity that doesn't mention a next step shouldn't
    // blank out one that's already scheduled ("whichever is more recent"
    // from the spec, implemented as "whichever was written most recently").
    const leadUpdate: Prisma.LeadUpdateInput = { leadStatus };
    if (nextAction !== null) leadUpdate.nextAction = nextAction;
    if (followUpDate !== null) leadUpdate.followUpDate = followUpDate;
    await tx.lead.update({ where: { id: leadDbId }, data: leadUpdate });

    // Auto-escalation: once a lead hits 3+ unanswered-call activities,
    // bump it to High priority. This only ever escalates — per product
    // decision, it never auto-reverts even if a later call connects, so a
    // person has to consciously decide the lead is no longer urgent.
    if (isUnansweredOutcome(callOutcome) && lead.priority !== Priority.High) {
      const unansweredCount = await tx.activity.count({
        where: { leadId: leadDbId, callOutcome: { in: UNANSWERED_OUTCOMES } },
      });
      if (unansweredCount >= UNANSWERED_ESCALATION_THRESHOLD) {
        await tx.lead.update({
          where: { id: leadDbId },
          data: { priority: Priority.High },
        });
      }
    }
  });

  revalidateActivityViews(leadDbId);
  redirect(`/leads/${leadDbId}`);
}
