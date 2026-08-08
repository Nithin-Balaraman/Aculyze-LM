import { getAllLeadsWithDerived, openLeadsOnly } from "@/lib/queries";
import { LeadTable } from "@/components/LeadTable";

export default async function FollowUpsPage() {
  const leads = openLeadsOnly(await getAllLeadsWithDerived());
  const followUps = leads
    .filter((lead) => lead.followUpDate !== null)
    .sort((a, b) => a.followUpDate!.getTime() - b.followUpDate!.getTime());

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-semibold text-brand-navy">Follow-Ups</h1>
        <p className="text-sm text-slate-500">
          Open leads with a pending follow-up date, soonest first. Overdue ones are flagged red.
        </p>
      </div>
      <LeadTable leads={followUps} showFollowUp emptyMessage="No pending follow-ups." />
    </div>
  );
}
