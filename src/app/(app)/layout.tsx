import { redirect } from "next/navigation";
import { getCurrentUser } from "@/lib/auth";
import { NavBar } from "@/components/NavBar";

export default async function AppLayout({ children }: { children: React.ReactNode }) {
  const user = await getCurrentUser();
  // Belt-and-braces: proxy.ts already redirects unauthenticated page loads
  // to /login, this just keeps the layout safe if it's ever rendered
  // without proxy in front of it.
  if (!user) redirect("/login");

  return (
    <div className="flex min-h-screen flex-col bg-slate-50">
      <NavBar userName={user.name} />
      <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6">{children}</main>
    </div>
  );
}
