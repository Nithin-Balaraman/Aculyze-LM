import { notFound } from "next/navigation";
import { getLeadById } from "@/lib/queries";
import { EditLeadForm } from "@/components/EditLeadForm";

export default async function EditLeadPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const leadDbId = Number(id);
  if (!Number.isInteger(leadDbId)) notFound();

  const lead = await getLeadById(leadDbId);
  if (!lead) notFound();

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-xl font-semibold text-brand-navy">Edit {lead.companyName}</h1>
      <EditLeadForm lead={lead} />
    </div>
  );
}
