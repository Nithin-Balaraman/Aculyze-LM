"use server";

import { redirect } from "next/navigation";
import { revalidatePath } from "next/cache";
import { LeadStatus, Priority } from "@/generated/prisma/client";
import { requireUser } from "@/lib/auth";
import { prisma } from "@/lib/db";
import { optionalDate, optionalString } from "@/lib/form-helpers";
import { formatLeadId } from "@/lib/ids";
import { findLeadsByExactCompanyName } from "@/lib/queries";

export type DuplicateMatch = { leadId: string; companyName: string; city: string | null };

export type CreateLeadState = {
  error?: string;
  duplicates?: DuplicateMatch[];
};

function revalidateLeadViews(leadDbId?: number) {
  revalidatePath("/");
  revalidatePath("/leads");
  revalidatePath("/follow-ups");
  revalidatePath("/appointments");
  revalidatePath("/unanswered");
  revalidatePath("/high-priority");
  revalidatePath("/data-quality");
  if (leadDbId) revalidatePath(`/leads/${leadDbId}`);
}

// Two-step submit: the first call (confirmDuplicate not set) checks for an
// existing company with the same name and, if found, returns the matches
// instead of saving — the form then shows a "create anyway?" confirmation
// and resubmits with confirmDuplicate=true to bypass this check.
export async function createLeadAction(
  _prevState: CreateLeadState,
  formData: FormData
): Promise<CreateLeadState> {
  const user = await requireUser();

  const companyName = optionalString(formData, "companyName");
  if (!companyName) {
    return { error: "Company name is required." };
  }

  const confirmDuplicate = formData.get("confirmDuplicate") === "true";
  if (!confirmDuplicate) {
    const matches = await findLeadsByExactCompanyName(companyName);
    if (matches.length > 0) {
      return {
        duplicates: matches.map((m) => ({
          leadId: m.leadId,
          companyName: m.companyName,
          city: m.city,
        })),
      };
    }
  }

  const priorityValue = optionalString(formData, "priority") as Priority | null;

  const lead = await prisma.$transaction(async (tx) => {
    const created = await tx.lead.create({
      data: {
        leadId: "PENDING",
        companyName,
        industry: optionalString(formData, "industry"),
        city: optionalString(formData, "city"),
        fullLocation: optionalString(formData, "fullLocation"),
        contactNumber: optionalString(formData, "contactNumber"),
        contactPerson: optionalString(formData, "contactPerson"),
        email: optionalString(formData, "email"),
        mobileNumber: optionalString(formData, "mobileNumber"),
        website: optionalString(formData, "website"),
        sourceSheet: optionalString(formData, "sourceSheet") ?? "Lead Entry",
        assignedOwner: optionalString(formData, "assignedOwner") ?? user.name,
        priority: priorityValue ?? Priority.Medium,
        leadStatus: LeadStatus.New,
      },
    });
    return tx.lead.update({
      where: { id: created.id },
      data: { leadId: formatLeadId(created.id) },
    });
  });

  revalidateLeadViews(lead.id);
  redirect(`/leads/${lead.id}`);
}

export type UpdateLeadState = { error?: string };

// Direct edits to a Lead's own fields — separate from logging an Activity.
// This is how nextAction/followUpDate/priority/assignedOwner get "manually
// set" per the spec, and how data-quality issues (e.g. a missing contact
// person) get corrected.
export async function updateLeadAction(
  _prevState: UpdateLeadState,
  formData: FormData
): Promise<UpdateLeadState> {
  await requireUser();

  const id = Number(formData.get("id"));
  if (!id) return { error: "Missing lead id." };

  const companyName = optionalString(formData, "companyName");
  if (!companyName) return { error: "Company name is required." };

  const leadStatus = optionalString(formData, "leadStatus") as LeadStatus | null;
  const priority = optionalString(formData, "priority") as Priority | null;

  await prisma.lead.update({
    where: { id },
    data: {
      companyName,
      industry: optionalString(formData, "industry"),
      city: optionalString(formData, "city"),
      fullLocation: optionalString(formData, "fullLocation"),
      contactNumber: optionalString(formData, "contactNumber"),
      contactPerson: optionalString(formData, "contactPerson"),
      email: optionalString(formData, "email"),
      mobileNumber: optionalString(formData, "mobileNumber"),
      website: optionalString(formData, "website"),
      assignedOwner: optionalString(formData, "assignedOwner"),
      dataQualityNote: optionalString(formData, "dataQualityNote"),
      nextAction: optionalString(formData, "nextAction"),
      followUpDate: optionalDate(formData, "followUpDate"),
      leadStatus: leadStatus ?? undefined,
      priority: priority ?? undefined,
    },
  });

  revalidateLeadViews(id);
  redirect(`/leads/${id}`);
}
