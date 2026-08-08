import { CallOutcome, Priority } from "@/generated/prisma/client";
import {
  isFollowUpOverdue,
  isUnansweredOutcome,
  needsAttentionToday,
} from "@/lib/leads";
import {
  getAllLeadsWithDerived,
  getTotalActivityCount,
  LeadWithDerived,
  openLeadsOnly,
  withDataQualityIssues,
} from "@/lib/queries";
import { LeadTable } from "@/components/LeadTable";
import { StatCard } from "@/components/StatCard";

function sortByUrgency(leads: LeadWithDerived[]): LeadWithDerived[] {
  const priorityRank: Record<Priority, number> = { High: 0, Medium: 1, Low: 2 };
  return [...leads].sort((a, b) => {
    const aDate = a.followUpDate ? a.followUpDate.getTime() : Infinity;
    const bDate = b.followUpDate ? b.followUpDate.getTime() : Infinity;
    if (aDate !== bDate) return aDate - bDate;
    return priorityRank[a.priority] - priorityRank[b.priority];
  });
}

// The home page: live dashboard counters (#12 in the spec) plus Today's
// Actions (#5) — nothing here is stored or cached, it's all computed fresh
// from the database on every load.
export default async function DashboardPage() {
  const allLeads = await getAllLeadsWithDerived();
  const totalActivities = await getTotalActivityCount();
  const openLeads = openLeadsOnly(allLeads);

  const openFollowUps = openLeads.filter((l) => l.followUpDate !== null);
  const overdueFollowUps = openLeads.filter((l) => isFollowUpOverdue(l.followUpDate));
  const appointments = openLeads.filter(
    (l) => l.latestCallOutcome === CallOutcome.AppointmentConfirmed
  );
  const unanswered = openLeads.filter(
    (l) => l.latestCallOutcome !== null && isUnansweredOutcome(l.latestCallOutcome)
  );
  const highPriority = openLeads.filter((l) => l.priority === Priority.High);
  const dataQualityIssues = withDataQualityIssues(allLeads);

  const todaysActions = sortByUrgency(
    openLeads.filter((l) => needsAttentionToday(l, l.lastActivityDate))
  );

  return (
    <div className="flex flex-col gap-8">
      <section>
        <h1 className="mb-4 text-xl font-semibold text-slate-900">Dashboard</h1>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <StatCard label="Total Leads" value={allLeads.length} href="/leads" />
          <StatCard label="Activities Logged" value={totalActivities} />
          <StatCard label="Open Follow-Ups" value={openFollowUps.length} href="/follow-ups" />
          <StatCard
            label="Overdue Follow-Ups"
            value={overdueFollowUps.length}
            href="/follow-ups"
            emphasis={overdueFollowUps.length > 0}
          />
          <StatCard label="Appointments" value={appointments.length} href="/appointments" />
          <StatCard label="Unanswered Leads" value={unanswered.length} href="/unanswered" />
          <StatCard label="High-Priority Leads" value={highPriority.length} href="/high-priority" />
          <StatCard
            label="Data Quality Issues"
            value={dataQualityIssues.length}
            href="/data-quality"
          />
        </div>
      </section>

      <section>
        <h2 className="mb-1 text-lg font-semibold text-slate-900">Today&apos;s Actions</h2>
        <p className="mb-3 text-sm text-slate-500">
          Open leads with a follow-up due, High priority, or no activity in 7+ days with nothing
          planned next.
        </p>
        <LeadTable
          leads={todaysActions}
          showFollowUp
          emptyMessage="Nothing needs attention right now — nice work."
        />
      </section>
    </div>
  );
}
