import { UNANSWERED_ESCALATION_THRESHOLD, isUnansweredOutcome } from "@/lib/leads";
import { getAllLeadsWithDerived, openLeadsOnly } from "@/lib/queries";
import { LeadTable } from "@/components/LeadTable";

export default async function UnansweredPage() {
  const leads = openLeadsOnly(await getAllLeadsWithDerived());
  const unanswered = leads
    .filter((lead) => lead.latestCallOutcome !== null && isUnansweredOutcome(lead.latestCallOutcome))
    .sort((a, b) => b.unansweredCount - a.unansweredCount);

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">Unanswered Leads</h1>
        <p className="text-sm text-slate-500">
          Open leads whose latest call outcome is No Answer or Unreachable. &quot;Unanswered
          Attempts&quot; counts every matching activity ever logged for that lead — hitting{" "}
          {UNANSWERED_ESCALATION_THRESHOLD}+ automatically escalates the lead to High priority.
        </p>
      </div>
      <LeadTable
        leads={unanswered}
        showUnansweredCount
        emptyMessage="No leads currently going unanswered."
      />
    </div>
  );
}
