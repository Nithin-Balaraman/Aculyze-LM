// Small helpers for turning raw FormData into clean, optional values.
// Empty strings become null so the database only ever stores "no value" one
// way, never a mix of null and "".

export function optionalString(formData: FormData, key: string): string | null {
  const raw = formData.get(key);
  if (typeof raw !== "string") return null;
  const trimmed = raw.trim();
  return trimmed.length > 0 ? trimmed : null;
}

export function requiredString(formData: FormData, key: string): string | null {
  return optionalString(formData, key);
}

export function optionalDate(formData: FormData, key: string): Date | null {
  const raw = optionalString(formData, key);
  if (!raw) return null;
  const date = new Date(raw);
  return Number.isNaN(date.getTime()) ? null : date;
}
