import { NextRequest, NextResponse } from "next/server";
import { getCurrentUser } from "@/lib/auth";
import { searchLeadsByCompanyName } from "@/lib/queries";

// Backs the type-ahead company search used both by the global nav search
// and the "log an activity" form's company picker.
export async function GET(request: NextRequest) {
  const user = await getCurrentUser();
  if (!user) {
    return NextResponse.json({ error: "Not authenticated." }, { status: 401 });
  }

  const query = request.nextUrl.searchParams.get("q") ?? "";
  const results = await searchLeadsByCompanyName(query);
  return NextResponse.json({ results });
}
