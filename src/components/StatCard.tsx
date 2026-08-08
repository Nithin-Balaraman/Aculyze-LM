import Link from "next/link";

export function StatCard({
  label,
  value,
  href,
  emphasis,
}: {
  label: string;
  value: number;
  href?: string;
  emphasis?: boolean;
}) {
  const content = (
    <div
      className={`rounded-lg border p-4 ${
        emphasis ? "border-red-200 bg-red-50" : "border-slate-200 bg-white"
      }`}
    >
      <div className={`text-2xl font-semibold ${emphasis ? "text-red-700" : "text-brand-navy"}`}>
        {value}
      </div>
      <div className="text-sm text-slate-500">{label}</div>
    </div>
  );

  return href ? (
    <Link href={href} className="block rounded-lg transition hover:ring-2 hover:ring-brand-cyan/40">
      {content}
    </Link>
  ) : (
    content
  );
}
