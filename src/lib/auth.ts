import "server-only";
import { cookies } from "next/headers";
import {
  SESSION_COOKIE_NAME,
  type SessionPayload,
  verifySessionToken,
} from "@/lib/session";

// Reads and verifies the session cookie for the current request. Use this
// in Server Components/pages, where a missing session should just render
// "logged out" UI (proxy.ts already redirects real page loads to /login).
export async function getCurrentUser(): Promise<SessionPayload | null> {
  const cookieStore = await cookies();
  const token = cookieStore.get(SESSION_COOKIE_NAME)?.value;
  if (!token) return null;
  return verifySessionToken(token);
}

// Every Server Action that mutates data calls this first. Proxy (src/proxy.ts)
// blocks unauthenticated page loads, but Server Actions are reachable by a
// direct POST even if a matcher change ever stopped covering their route —
// so each action re-checks auth itself rather than trusting Proxy alone.
export async function requireUser(): Promise<SessionPayload> {
  const user = await getCurrentUser();
  if (!user) {
    throw new Error("Not authenticated.");
  }
  return user;
}
