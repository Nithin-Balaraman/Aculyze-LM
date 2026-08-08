"use client";

import { startTransition, useActionState, useState } from "react";
import { Priority } from "@/generated/prisma/enums";
import { createLeadAction, type CreateLeadState } from "@/lib/actions/leads";

const initialState: CreateLeadState = {};
const LEAD_TEXT_FIELDS = [
  "companyName",
  "industry",
  "city",
  "fullLocation",
  "contactPerson",
  "contactNumber",
  "mobileNumber",
  "email",
  "website",
  "assignedOwner",
] as const;

type FieldName = (typeof LEAD_TEXT_FIELDS)[number];

export function NewLeadForm() {
  const [state, formAction, pending] = useActionState(createLeadAction, initialState);
  // Fields are controlled (rather than plain uncontrolled inputs) because
  // React resets uncontrolled form fields after any form action completes
  // successfully — including the "check for duplicates" round-trip. Without
  // this, the company name the user just typed would visibly vanish the
  // moment the duplicate warning appears.
  const [values, setValues] = useState<Record<FieldName, string>>({
    companyName: "",
    industry: "",
    city: "",
    fullLocation: "",
    contactPerson: "",
    contactNumber: "",
    mobileNumber: "",
    email: "",
    website: "",
    assignedOwner: "",
  });
  const [priority, setPriority] = useState<Priority>(Priority.Medium);

  function fieldProps(name: FieldName) {
    return {
      value: values[name],
      onChange: (e: React.ChangeEvent<HTMLInputElement>) =>
        setValues((v) => ({ ...v, [name]: e.target.value })),
    };
  }

  function buildFormData(confirmDuplicate: boolean): FormData {
    const data = new FormData();
    for (const field of LEAD_TEXT_FIELDS) data.set(field, values[field]);
    data.set("priority", priority);
    data.set("confirmDuplicate", confirmDuplicate ? "true" : "false");
    return data;
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    startTransition(() => formAction(buildFormData(false)));
  }

  function confirmCreateAnyway() {
    startTransition(() => formAction(buildFormData(true)));
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <TextField name="companyName" label="Company Name" required {...fieldProps("companyName")} />
        <TextField name="industry" label="Industry" {...fieldProps("industry")} />
        <TextField name="city" label="City" {...fieldProps("city")} />
        <TextField name="fullLocation" label="Full Location" {...fieldProps("fullLocation")} />
        <TextField name="contactPerson" label="Contact Person" {...fieldProps("contactPerson")} />
        <TextField name="contactNumber" label="Contact Number" {...fieldProps("contactNumber")} />
        <TextField name="mobileNumber" label="Mobile Number" {...fieldProps("mobileNumber")} />
        <TextField name="email" label="Email" type="email" {...fieldProps("email")} />
        <TextField name="website" label="Website" {...fieldProps("website")} />
        <TextField
          name="assignedOwner"
          label="Assigned Owner"
          placeholder="Defaults to you"
          {...fieldProps("assignedOwner")}
        />

        <div className="flex flex-col gap-1">
          <label className="text-sm font-medium text-slate-700">Priority</label>
          <select
            value={priority}
            onChange={(e) => setPriority(e.target.value as Priority)}
            className="rounded-md border border-slate-300 px-3 py-2 text-base"
          >
            {Object.values(Priority).map((p) => (
              <option key={p} value={p}>
                {p}
              </option>
            ))}
          </select>
        </div>
      </div>

      {state.error && <p className="text-sm text-red-600">{state.error}</p>}

      {state.duplicates && state.duplicates.length > 0 && (
        <div className="rounded-md border border-amber-300 bg-amber-50 p-4">
          <p className="text-sm font-medium text-amber-800">
            A company with this name already exists:
          </p>
          <ul className="mt-1 list-inside list-disc text-sm text-amber-700">
            {state.duplicates.map((d) => (
              <li key={d.leadId}>
                {d.companyName} ({d.leadId}
                {d.city ? `, ${d.city}` : ""})
              </li>
            ))}
          </ul>
          <button
            type="button"
            onClick={confirmCreateAnyway}
            className="mt-3 rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white"
          >
            Create anyway
          </button>
        </div>
      )}

      <div>
        <button
          type="submit"
          disabled={pending}
          className="rounded-md bg-slate-900 px-4 py-2 text-base font-medium text-white disabled:opacity-60"
        >
          {pending ? "Saving..." : "Add Lead"}
        </button>
      </div>
    </form>
  );
}

function TextField({
  name,
  label,
  type = "text",
  required,
  placeholder,
  value,
  onChange,
}: {
  name: string;
  label: string;
  type?: string;
  required?: boolean;
  placeholder?: string;
  value: string;
  onChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
}) {
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={name} className="text-sm font-medium text-slate-700">
        {label}
      </label>
      <input
        id={name}
        name={name}
        type={type}
        required={required}
        placeholder={placeholder}
        value={value}
        onChange={onChange}
        className="rounded-md border border-slate-300 px-3 py-2 text-base focus:border-slate-500 focus:outline-none"
      />
    </div>
  );
}
