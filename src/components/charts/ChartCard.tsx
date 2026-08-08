export function ChartCard({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-4">
      <h3 className="text-sm font-semibold text-brand-navy">{title}</h3>
      {subtitle && <p className="mb-2 text-xs text-slate-500">{subtitle}</p>}
      <div className="h-64 w-full">{children}</div>
    </div>
  );
}
