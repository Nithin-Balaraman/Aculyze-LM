"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { logoutAction } from "@/lib/actions/auth";
import { CompanySearch } from "@/components/CompanySearch";

const NAV_LINKS = [
  { href: "/", label: "Dashboard" },
  { href: "/leads", label: "All Leads" },
  { href: "/follow-ups", label: "Follow-Ups" },
  { href: "/appointments", label: "Appointments" },
  { href: "/unanswered", label: "Unanswered" },
  { href: "/high-priority", label: "High Priority" },
  { href: "/data-quality", label: "Data Quality" },
];

export function NavBar({ userName }: { userName: string }) {
  const pathname = usePathname();
  const router = useRouter();

  return (
    <header className="border-b border-slate-200 bg-white">
      <div className="mx-auto flex max-w-6xl flex-col gap-3 px-4 py-3">
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
          <Link href="/" className="whitespace-nowrap text-lg font-semibold text-slate-900">
            Aculyze LM
          </Link>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
            <Link
              href="/leads/new"
              className="whitespace-nowrap rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white"
            >
              + New Lead
            </Link>
            <Link
              href="/activities/new"
              className="whitespace-nowrap rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700"
            >
              + Log Activity
            </Link>
            <span className="hidden whitespace-nowrap text-sm text-slate-500 sm:inline">
              {userName}
            </span>
            <form action={logoutAction}>
              <button type="submit" className="whitespace-nowrap text-sm text-slate-500 underline">
                Log out
              </button>
            </form>
          </div>
        </div>

        <CompanySearch
          placeholder="Search companies..."
          className="max-w-md"
          onSelect={(result) => router.push(`/leads/${result.id}`)}
        />

        <nav className="-mx-4 flex gap-1 overflow-x-auto whitespace-nowrap px-4 text-sm">
          {NAV_LINKS.map((link) => {
            const active = pathname === link.href;
            return (
              <Link
                key={link.href}
                href={link.href}
                className={`rounded-md px-3 py-1.5 font-medium ${
                  active ? "bg-slate-900 text-white" : "text-slate-600 hover:bg-slate-100"
                }`}
              >
                {link.label}
              </Link>
            );
          })}
        </nav>
      </div>
    </header>
  );
}
