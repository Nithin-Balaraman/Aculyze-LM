import { getAllLeadsWithDerived } from "@/lib/queries";
import { LeadTable } from "@/components/LeadTable";

export default async function LeadsPage({
  searchParams,
}: {
  searchParams: Promise<{ q?: string }>;
}) {
  const { q } = await searchParams;
  const leads = await getAllLeadsWithDerived();
  const filtered = q
    ? leads.filter((lead) => lead.companyName.toLowerCase().includes(q.toLowerCase()))
    : leads;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-semibold text-brand-navy">All Leads</h1>
        <p className="text-sm text-slate-500">{leads.length} total (includes Won/Lost)</p>
      </div>

      <form method="get" className="max-w-sm">
        <input
          type="text"
          name="q"
          defaultValue={q}
          placeholder="Filter by company name..."
          className="w-full rounded-md border border-slate-300 px-3 py-2 text-base focus:border-brand-cyan focus:outline-none"
        />
      </form>

      <LeadTable leads={filtered} showFollowUp emptyMessage="No leads match that filter." />
    </div>
  );
}
