import type { Activity } from "@/generated/prisma/client";
import {
  ACTIVITY_TYPE_LABELS,
  CALL_OUTCOME_LABELS,
  CONTACT_CHANNEL_LABELS,
  formatDate,
  formatDateTime,
} from "@/lib/format";
import { StatusBadge } from "@/components/Badges";

export function ActivityTimeline({ activities }: { activities: Activity[] }) {
  if (activities.length === 0) {
    return (
      <p className="rounded-lg border border-dashed border-slate-300 p-6 text-sm text-slate-500">
        No activity logged yet.
      </p>
    );
  }

  return (
    <ol className="flex flex-col gap-3">
      {activities.map((activity) => (
        <li key={activity.id} className="rounded-lg border border-slate-200 bg-white p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <span className="text-sm font-medium text-slate-900">
                {ACTIVITY_TYPE_LABELS[activity.activityType]}
              </span>
              <span className="text-xs text-slate-400">{activity.activityId}</span>
            </div>
            <span className="text-xs text-slate-500">{formatDateTime(activity.activityDate)}</span>
          </div>
          <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600">
            <span>Channel: {CONTACT_CHANNEL_LABELS[activity.contactChannel]}</span>
            <span>Outcome: {CALL_OUTCOME_LABELS[activity.callOutcome]}</span>
            <span className="flex items-center gap-1">
              Status set to: <StatusBadge status={activity.leadStatus} />
            </span>
            {activity.assignedOwner && <span>By: {activity.assignedOwner}</span>}
          </div>
          {activity.detailsRemarks && (
            <p className="mt-2 text-sm text-slate-700">{activity.detailsRemarks}</p>
          )}
          {(activity.nextAction || activity.followUpDate) && (
            <p className="mt-2 text-sm text-slate-500">
              Next: {activity.nextAction ?? "—"}
              {activity.followUpDate && ` (by ${formatDate(activity.followUpDate)})`}
            </p>
          )}
        </li>
      ))}
    </ol>
  );
}
