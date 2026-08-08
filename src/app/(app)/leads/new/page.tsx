import { NewLeadForm } from "@/components/NewLeadForm";

export default function NewLeadPage() {
  return (
    <div className="flex max-w-3xl flex-col gap-4">
      <h1 className="text-xl font-semibold text-slate-900">Add a New Lead</h1>
      <NewLeadForm />
    </div>
  );
}
