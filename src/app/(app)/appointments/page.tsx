import { CallOutcome } from "@/generated/prisma/client";
import { getAllLeadsWithDerived, openLeadsOnly } from "@/lib/queries";
import { LeadTable } from "@/components/LeadTable";

export default async function AppointmentsPage() {
  const leads = openLeadsOnly(await getAllLeadsWithDerived());
  const appointments = leads.filter(
    (lead) => lead.latestCallOutcome === CallOutcome.AppointmentConfirmed
  );

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">Appointments</h1>
        <p className="text-sm text-slate-500">
          Open leads whose latest activity outcome is Appointment Confirmed.
        </p>
      </div>
      <LeadTable leads={appointments} showFollowUp emptyMessage="No confirmed appointments right now." />
    </div>
  );
}
