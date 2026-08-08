import { jwtVerify, SignJWT } from "jose";

// Cookie-based session: a signed JWT holding just the user's id and name.
// No server-side session table to manage — the signature is what makes it
// trustworthy, so there's nothing to look up or expire on the server side
// besides the cookie's own maxAge.

export const SESSION_COOKIE_NAME = "aculyze_session";
const SESSION_DURATION_SECONDS = 60 * 60 * 24 * 30; // 30 days

export type SessionPayload = {
  userId: number;
  name: string;
};

function getSecretKey(): Uint8Array {
  const secret = process.env.SESSION_SECRET;
  if (!secret) {
    throw new Error("SESSION_SECRET environment variable is not set.");
  }
  return new TextEncoder().encode(secret);
}

export async function createSessionToken(
  payload: SessionPayload
): Promise<string> {
  return new SignJWT(payload)
    .setProtectedHeader({ alg: "HS256" })
    .setIssuedAt()
    .setExpirationTime(`${SESSION_DURATION_SECONDS}s`)
    .sign(getSecretKey());
}

export async function verifySessionToken(
  token: string
): Promise<SessionPayload | null> {
  try {
    const { payload } = await jwtVerify(token, getSecretKey());
    if (typeof payload.userId === "number" && typeof payload.name === "string") {
      return { userId: payload.userId, name: payload.name };
    }
    return null;
  } catch {
    return null; // missing, expired, or tampered token
  }
}

export const SESSION_MAX_AGE = SESSION_DURATION_SECONDS;
