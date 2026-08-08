import { getCompanySearchResultById } from "@/lib/queries";
import { NewActivityForm } from "@/components/NewActivityForm";

export default async function NewActivityPage({
  searchParams,
}: {
  searchParams: Promise<{ leadId?: string }>;
}) {
  const { leadId } = await searchParams;
  const parsedId = leadId ? Number(leadId) : null;
  const preselected =
    parsedId && Number.isInteger(parsedId) ? await getCompanySearchResultById(parsedId) : null;

  return (
    <div className="flex max-w-3xl flex-col gap-4">
      <h1 className="text-xl font-semibold text-slate-900">Log an Activity</h1>
      <NewActivityForm preselected={preselected} />
    </div>
  );
}
