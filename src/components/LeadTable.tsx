import Link from "next/link";
import { isFollowUpOverdue } from "@/lib/leads";
import type { LeadWithDerived } from "@/lib/queries";
import { CALL_OUTCOME_LABELS, formatDate } from "@/lib/format";
import { PriorityBadge, StatusBadge } from "@/components/Badges";

type LeadTableProps = {
  leads: LeadWithDerived[];
  emptyMessage?: string;
  showFollowUp?: boolean;
  showUnansweredCount?: boolean;
};

// One shared table for every lead-list view (dashboard, follow-ups,
// appointments, unanswered, high-priority, all leads) so they all look and
// behave the same way — only the optional columns and the incoming lead
// list differ per view.
export function LeadTable({
  leads,
  emptyMessage = "No leads to show.",
  showFollowUp = false,
  showUnansweredCount = false,
}: LeadTableProps) {
  if (leads.length === 0) {
    return <p className="rounded-lg border border-dashed border-slate-300 p-6 text-sm text-slate-500">{emptyMessage}</p>;
  }

  return (
    <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table className="w-full min-w-[760px] text-sm">
        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th className="px-3 py-2">Company</th>
            <th className="px-3 py-2">Status</th>
            <th className="px-3 py-2">Priority</th>
            <th className="px-3 py-2">Latest Outcome</th>
            <th className="px-3 py-2">Last Activity</th>
            {showFollowUp && <th className="px-3 py-2">Follow-up</th>}
            {showUnansweredCount && <th className="px-3 py-2">Unanswered Attempts</th>}
            <th className="px-3 py-2">Next Action</th>
            <th className="px-3 py-2">Owner</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {leads.map((lead) => {
            const overdue = isFollowUpOverdue(lead.followUpDate);
            return (
              <tr key={lead.id} className="hover:bg-slate-50">
                <td className="px-3 py-2">
                  <Link href={`/leads/${lead.id}`} className="font-medium text-slate-900 hover:underline">
                    {lead.companyName}
                  </Link>
                  <div className="text-xs text-slate-400">{lead.leadId}</div>
                </td>
                <td className="px-3 py-2">
                  <StatusBadge status={lead.leadStatus} />
                </td>
                <td className="px-3 py-2">
                  <PriorityBadge priority={lead.priority} />
                </td>
                <td className="px-3 py-2 text-slate-600">
                  {lead.latestCallOutcome ? CALL_OUTCOME_LABELS[lead.latestCallOutcome] : "—"}
                </td>
                <td className="px-3 py-2 text-slate-600">{formatDate(lead.lastActivityDate)}</td>
                {showFollowUp && (
                  <td className={`px-3 py-2 ${overdue ? "font-semibold text-red-600" : "text-slate-600"}`}>
                    {formatDate(lead.followUpDate)}
                    {overdue && " · overdue"}
                  </td>
                )}
                {showUnansweredCount && (
                  <td className="px-3 py-2 text-slate-600">{lead.unansweredCount}</td>
                )}
                <td className="px-3 py-2 text-slate-600">{lead.nextAction ?? "—"}</td>
                <td className="px-3 py-2 text-slate-600">{lead.assignedOwner ?? "—"}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
