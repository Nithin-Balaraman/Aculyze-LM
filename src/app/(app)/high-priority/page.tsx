import { Priority } from "@/generated/prisma/client";
import { getAllLeadsWithDerived, openLeadsOnly } from "@/lib/queries";
import { LeadTable } from "@/components/LeadTable";

export default async function HighPriorityPage() {
  const leads = openLeadsOnly(await getAllLeadsWithDerived());
  const highPriority = leads.filter((lead) => lead.priority === Priority.High);

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-semibold text-brand-navy">High-Priority Leads</h1>
        <p className="text-sm text-slate-500">Open leads currently marked High priority.</p>
      </div>
      <LeadTable leads={highPriority} showFollowUp emptyMessage="No high-priority leads right now." />
    </div>
  );
}
