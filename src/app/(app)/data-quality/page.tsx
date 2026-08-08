import Link from "next/link";
import { DATA_QUALITY_LABELS } from "@/lib/leads";
import { getAllLeadsWithDerived, withDataQualityIssues } from "@/lib/queries";
import { StatusBadge } from "@/components/Badges";

export default async function DataQualityPage() {
  const allLeads = await getAllLeadsWithDerived();
  const flagged = withDataQualityIssues(allLeads);

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">Data Quality</h1>
        <p className="text-sm text-slate-500">
          Every lead (including Won/Lost) with a missing contact person, no phone or mobile
          number, a malformed email, or a duplicate company name. One row per lead.
        </p>
      </div>

      {flagged.length === 0 ? (
        <p className="rounded-lg border border-dashed border-slate-300 p-6 text-sm text-slate-500">
          No data quality issues found.
        </p>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
          <table className="w-full min-w-[720px] text-sm">
            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-3 py-2">Company</th>
                <th className="px-3 py-2">Status</th>
                <th className="px-3 py-2">Issues</th>
                <th className="px-3 py-2">Contact Person</th>
                <th className="px-3 py-2">Phone / Mobile</th>
                <th className="px-3 py-2">Email</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {flagged.map((lead) => (
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
                  <td className="px-3 py-2 text-amber-700">
                    <ul className="list-inside list-disc">
                      {lead.issues.map((issue) => (
                        <li key={issue}>{DATA_QUALITY_LABELS[issue]}</li>
                      ))}
                    </ul>
                  </td>
                  <td className="px-3 py-2 text-slate-600">{lead.contactPerson ?? "—"}</td>
                  <td className="px-3 py-2 text-slate-600">
                    {lead.contactNumber ?? lead.mobileNumber ?? "—"}
                  </td>
                  <td className="px-3 py-2 text-slate-600">{lead.email ?? "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
