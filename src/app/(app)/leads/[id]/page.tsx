import Link from "next/link";
import { notFound } from "next/navigation";
import { getDataQualityIssuesForLead, getLeadById } from "@/lib/queries";
import { DATA_QUALITY_LABELS } from "@/lib/leads";
import { CALL_OUTCOME_LABELS, formatDate } from "@/lib/format";
import { PriorityBadge, StatusBadge } from "@/components/Badges";
import { ActivityTimeline } from "@/components/ActivityTimeline";

function Field({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="break-words text-sm text-slate-800">{value || "—"}</dd>
    </div>
  );
}

export default async function LeadDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const leadDbId = Number(id);
  if (!Number.isInteger(leadDbId)) notFound();

  const lead = await getLeadById(leadDbId);
  if (!lead) notFound();

  const issues = await getDataQualityIssuesForLead(lead);

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-xs uppercase tracking-wide text-slate-400">{lead.leadId}</p>
          <h1 className="text-xl font-semibold text-slate-900">{lead.companyName}</h1>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <StatusBadge status={lead.leadStatus} />
            <PriorityBadge priority={lead.priority} />
            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
              {lead.actionStatusValue}
            </span>
          </div>
        </div>
        <div className="flex gap-2">
          <Link
            href={`/activities/new?leadId=${lead.id}`}
            className="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white"
          >
            Log Activity
          </Link>
          <Link
            href={`/leads/${lead.id}/edit`}
            className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700"
          >
            Edit Lead
          </Link>
        </div>
      </div>

      {issues.length > 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
          <p className="text-sm font-medium text-amber-800">Data quality issues:</p>
          <ul className="mt-1 list-inside list-disc text-sm text-amber-700">
            {issues.map((issue) => (
              <li key={issue}>{DATA_QUALITY_LABELS[issue]}</li>
            ))}
          </ul>
        </div>
      )}

      <section className="rounded-lg border border-slate-200 bg-white p-4">
        <h2 className="mb-3 text-sm font-semibold text-slate-900">Latest activity summary</h2>
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Field
            label="Latest Call Outcome"
            value={lead.latestCallOutcome ? CALL_OUTCOME_LABELS[lead.latestCallOutcome] : null}
          />
          <Field label="Latest Remarks" value={lead.latestRemarks} />
          <Field label="Last Activity Date" value={formatDate(lead.lastActivityDate)} />
          <Field label="Next Action" value={lead.nextAction} />
          <Field label="Follow-up Date" value={formatDate(lead.followUpDate)} />
          <Field label="Assigned Owner" value={lead.assignedOwner} />
        </dl>
      </section>

      <section className="rounded-lg border border-slate-200 bg-white p-4">
        <h2 className="mb-3 text-sm font-semibold text-slate-900">Company details</h2>
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
          <Field label="Industry" value={lead.industry} />
          <Field label="City" value={lead.city} />
          <Field label="Full Location" value={lead.fullLocation} />
          <Field label="Contact Person" value={lead.contactPerson} />
          <Field label="Contact Number" value={lead.contactNumber} />
          <Field label="Mobile Number" value={lead.mobileNumber} />
          <Field label="Email" value={lead.email} />
          <Field label="Website" value={lead.website} />
          <Field label="Source" value={lead.sourceSheet} />
          <Field label="Data Quality Note" value={lead.dataQualityNote} />
        </dl>
      </section>

      <section>
        <h2 className="mb-3 text-lg font-semibold text-slate-900">Activity History</h2>
        <ActivityTimeline activities={lead.activities} />
      </section>
    </div>
  );
}
