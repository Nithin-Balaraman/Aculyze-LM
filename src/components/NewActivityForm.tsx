"use client";

import { useState } from "react";
import { useActionState } from "react";
import {
  ActivityType,
  CallOutcome,
  ContactChannel,
  LeadStatus,
} from "@/generated/prisma/enums";
import { createActivityAction, type CreateActivityState } from "@/lib/actions/activities";
import { ACTIVITY_TYPE_LABELS, CALL_OUTCOME_LABELS, CONTACT_CHANNEL_LABELS } from "@/lib/format";
import { CompanySearch } from "@/components/CompanySearch";
import type { CompanySearchResult } from "@/lib/queries";

const initialState: CreateActivityState = {};

export function NewActivityForm({ preselected }: { preselected: CompanySearchResult | null }) {
  const [lead, setLead] = useState<CompanySearchResult | null>(preselected);
  const [state, formAction, pending] = useActionState(createActivityAction, initialState);

  return (
    <form action={formAction} className="flex flex-col gap-5">
      <div className="flex flex-col gap-1">
        <label className="text-sm font-medium text-slate-700">Company</label>
        {lead ? (
          <div className="flex items-center justify-between rounded-md border border-slate-300 bg-slate-50 px-3 py-2">
            <div>
              <div className="font-medium text-slate-900">{lead.companyName}</div>
              <div className="text-xs text-slate-500">{lead.leadId}</div>
            </div>
            <button
              type="button"
              onClick={() => setLead(null)}
              className="text-sm text-slate-500 underline"
            >
              Change
            </button>
          </div>
        ) : (
          <CompanySearch placeholder="Search for the company..." onSelect={setLead} autoFocus />
        )}
        <input type="hidden" name="leadDbId" value={lead?.id ?? ""} />
      </div>

      {lead && (
        <div className="grid grid-cols-2 gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm sm:grid-cols-4">
          <ReadOnlyField label="Industry" value={lead.industry} />
          <ReadOnlyField label="Location" value={lead.city ?? lead.fullLocation} />
          <ReadOnlyField label="Contact Person" value={lead.contactPerson} />
          <ReadOnlyField label="Telephone" value={lead.contactNumber} />
          <ReadOnlyField label="Mobile" value={lead.mobileNumber} />
          <ReadOnlyField label="Email" value={lead.email} />
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-1">
          <label className="text-sm font-medium text-slate-700">Activity Type</label>
          <select name="activityType" required className="rounded-md border border-slate-300 px-3 py-2 text-base">
            <option value="">Select...</option>
            {Object.values(ActivityType).map((type) => (
              <option key={type} value={type}>
                {ACTIVITY_TYPE_LABELS[type]}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col gap-1">
          <label className="text-sm font-medium text-slate-700">Contact Channel</label>
          <select name="contactChannel" required className="rounded-md border border-slate-300 px-3 py-2 text-base">
            <option value="">Select...</option>
            {Object.values(ContactChannel).map((channel) => (
              <option key={channel} value={channel}>
                {CONTACT_CHANNEL_LABELS[channel]}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col gap-1">
          <label className="text-sm font-medium text-slate-700">Call Outcome</label>
          <select name="callOutcome" required className="rounded-md border border-slate-300 px-3 py-2 text-base">
            <option value="">Select...</option>
            {Object.values(CallOutcome).map((outcome) => (
              <option key={outcome} value={outcome}>
                {CALL_OUTCOME_LABELS[outcome]}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col gap-1">
          <label className="text-sm font-medium text-slate-700">Lead Status (as of this activity)</label>
          <select
            name="leadStatus"
            required
            defaultValue={lead?.leadStatus ?? ""}
            className="rounded-md border border-slate-300 px-3 py-2 text-base"
          >
            <option value="">Select...</option>
            {Object.values(LeadStatus).map((status) => (
              <option key={status} value={status}>
                {status}
              </option>
            ))}
          </select>
        </div>
      </div>

      <div className="flex flex-col gap-1">
        <label className="text-sm font-medium text-slate-700">Details / Remarks</label>
        <textarea
          name="detailsRemarks"
          rows={3}
          className="rounded-md border border-slate-300 px-3 py-2 text-base"
        />
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div className="flex flex-col gap-1 sm:col-span-2">
          <label className="text-sm font-medium text-slate-700">Next Action</label>
          <input
            type="text"
            name="nextAction"
            className="rounded-md border border-slate-300 px-3 py-2 text-base"
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-sm font-medium text-slate-700">Follow-up Date</label>
          <input
            type="date"
            name="followUpDate"
            className="rounded-md border border-slate-300 px-3 py-2 text-base"
          />
        </div>
      </div>

      <div className="flex flex-col gap-1 sm:max-w-xs">
        <label className="text-sm font-medium text-slate-700">Assigned Owner</label>
        <input
          type="text"
          name="assignedOwner"
          placeholder="Defaults to you"
          className="rounded-md border border-slate-300 px-3 py-2 text-base"
        />
      </div>

      {state.error && <p className="text-sm text-red-600">{state.error}</p>}

      <div>
        <button
          type="submit"
          disabled={pending || !lead}
          className="rounded-md bg-slate-900 px-4 py-2 text-base font-medium text-white disabled:opacity-60"
        >
          {pending ? "Saving..." : "Log Activity"}
        </button>
      </div>
    </form>
  );
}

function ReadOnlyField({ label, value }: { label: string; value: string | null }) {
  return (
    <div>
      <div className="text-xs uppercase tracking-wide text-slate-400">{label}</div>
      <div className="text-slate-700">{value || "—"}</div>
    </div>
  );
}
