import { NextRequest, NextResponse } from "next/server";

const ROLE_SECTIONS = ["customer", "admin"] as const;

/** Which URL section (/customer, /admin) each role is allowed into. */
const ALLOWED_SECTION: Record<string, (typeof ROLE_SECTIONS)[number]> = {
  customer: "customer",
  admin: "admin",
  super_admin: "admin",
};

/**
 * Gates /customer, /admin route groups by the auth_role cookie set
 * at login (see lib/auth.ts). This is a cheap first line of defense — every
 * API request is still authorized independently by the Laravel backend's
 * role/permission middleware.
 */
export function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;

  const section = ROLE_SECTIONS.find((s) => pathname.startsWith(`/${s}`));
  if (!section) {
    return NextResponse.next();
  }

  const token = request.cookies.get("auth_token")?.value;
  const role = request.cookies.get("auth_role")?.value;

  if (!token || !role) {
    const loginUrl = new URL("/login", request.url);
    loginUrl.searchParams.set("next", pathname);
    return NextResponse.redirect(loginUrl);
  }

  const allowedSection = ALLOWED_SECTION[role];

  if (allowedSection !== section) {
    return NextResponse.redirect(new URL(`/${allowedSection ?? "login"}/dashboard`, request.url));
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/customer/:path*", "/admin/:path*"],
};
