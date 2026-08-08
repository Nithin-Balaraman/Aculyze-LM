"use client";

import { useActionState } from "react";
import { LeadStatus, Priority } from "@/generated/prisma/enums";
import { updateLeadAction, type UpdateLeadState } from "@/lib/actions/leads";
import { toDateInputValue } from "@/lib/format";
import type { LeadDetail } from "@/lib/queries";

const initialState: UpdateLeadState = {};

export function EditLeadForm({ lead }: { lead: LeadDetail }) {
  const [state, formAction, pending] = useActionState(updateLeadAction, initialState);

  return (
    <form action={formAction} className="flex flex-col gap-4">
      <input type="hidden" name="id" value={lead.id} />

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <TextField name="companyName" label="Company Name" defaultValue={lead.companyName} required />
        <TextField name="industry" label="Industry" defaultValue={lead.industry ?? ""} />
        <TextField name="city" label="City" defaultValue={lead.city ?? ""} />
        <TextField name="fullLocation" label="Full Location" defaultValue={lead.fullLocation ?? ""} />
        <TextField name="contactPerson" label="Contact Person" defaultValue={lead.contactPerson ?? ""} />
        <TextField name="contactNumber" label="Contact Number" defaultValue={lead.contactNumber ?? ""} />
        <TextField name="mobileNumber" label="Mobile Number" defaultValue={lead.mobileNumber ?? ""} />
        <TextField name="email" label="Email" defaultValue={lead.email ?? ""} />
        <TextField name="website" label="Website" defaultValue={lead.website ?? ""} />
        <TextField name="assignedOwner" label="Assigned Owner" defaultValue={lead.assignedOwner ?? ""} />

        <div className="flex flex-col gap-1">
          <label className="text-sm font-medium text-slate-700">Lead Status</label>
          <select
            name="leadStatus"
            defaultValue={lead.leadStatus}
            className="rounded-md border border-slate-300 px-3 py-2 text-base"
          >
            {Object.values(LeadStatus).map((status) => (
              <option key={status} value={status}>
                {status}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col gap-1">
          <label className="text-sm font-medium text-slate-700">Priority</label>
          <select
            name="priority"
            defaultValue={lead.priority}
            className="rounded-md border border-slate-300 px-3 py-2 text-base"
          >
            {Object.values(Priority).map((priority) => (
              <option key={priority} value={priority}>
                {priority}
              </option>
            ))}
          </select>
        </div>

        <TextField name="nextAction" label="Next Action" defaultValue={lead.nextAction ?? ""} />

        <div className="flex flex-col gap-1">
          <label className="text-sm font-medium text-slate-700">Follow-up Date</label>
          <input
            type="date"
            name="followUpDate"
            defaultValue={toDateInputValue(lead.followUpDate)}
            className="rounded-md border border-slate-300 px-3 py-2 text-base"
          />
        </div>
      </div>

      <div className="flex flex-col gap-1">
        <label className="text-sm font-medium text-slate-700">Data Quality Note</label>
        <textarea
          name="dataQualityNote"
          defaultValue={lead.dataQualityNote ?? ""}
          rows={2}
          className="rounded-md border border-slate-300 px-3 py-2 text-base"
        />
      </div>

      {state.error && <p className="text-sm text-red-600">{state.error}</p>}

      <div>
        <button
          type="submit"
          disabled={pending}
          className="rounded-md bg-slate-900 px-4 py-2 text-base font-medium text-white disabled:opacity-60"
        >
          {pending ? "Saving..." : "Save Changes"}
        </button>
      </div>
    </form>
  );
}

function TextField({
  name,
  label,
  defaultValue,
  required,
}: {
  name: string;
  label: string;
  defaultValue: string;
  required?: boolean;
}) {
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={name} className="text-sm font-medium text-slate-700">
        {label}
      </label>
      <input
        id={name}
        name={name}
        type="text"
        defaultValue={defaultValue}
        required={required}
        className="rounded-md border border-slate-300 px-3 py-2 text-base focus:border-slate-500 focus:outline-none"
      />
    </div>
  );
}
