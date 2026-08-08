// Display codes (LD-0001, ACT-0001) are derived from each row's real
// autoincrement `id` right after insert, inside the same transaction, so
// they always stay in lockstep with `id` and there's no separate counter
// table that could get out of sync.

export function formatLeadId(id: number): string {
  return `LD-${String(id).padStart(4, "0")}`;
}

export function formatActivityId(id: number): string {
  return `ACT-${String(id).padStart(4, "0")}`;
}
