import type { ActivityType, CallOutcome, ContactChannel } from "@/generated/prisma/enums";

// The Prisma enum identifiers can't contain spaces or slashes, so this maps
// them back to the display labels from the spec (e.g. ActivityType.ColdCall
// -> "Cold Call", ActivityType.Appointment -> "Appointment/Meeting").

export const ACTIVITY_TYPE_LABELS: Record<ActivityType, string> = {
  ColdCall: "Cold Call",
  FollowUp: "Follow-up",
  Appointment: "Appointment/Meeting",
  Email: "Email",
  WhatsApp: "WhatsApp",
  Other: "Other",
};

export const CONTACT_CHANNEL_LABELS: Record<ContactChannel, string> = {
  Phone: "Phone",
  Email: "Email",
  WhatsApp: "WhatsApp",
  InPerson: "In-person",
  Other: "Other",
};

export const CALL_OUTCOME_LABELS: Record<CallOutcome, string> = {
  Connected: "Connected",
  NoAnswer: "No Answer",
  Unreachable: "Unreachable / Invalid",
  AppointmentConfirmed: "Appointment Confirmed",
  NotInterested: "Not Interested",
  CallbackRequested: "Callback Requested",
  Other: "Other",
};

export function formatDate(date: Date | string | null): string {
  if (!date) return "—";
  const d = typeof date === "string" ? new Date(date) : date;
  return d.toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" });
}

export function formatDateTime(date: Date | string | null): string {
  if (!date) return "—";
  const d = typeof date === "string" ? new Date(date) : date;
  return d.toLocaleString("en-IN", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export function toDateInputValue(date: Date | string | null): string {
  if (!date) return "";
  const d = typeof date === "string" ? new Date(date) : date;
  return d.toISOString().slice(0, 10);
}
