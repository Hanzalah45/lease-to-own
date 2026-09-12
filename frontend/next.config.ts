import type { NextConfig } from "next";

/**
 * Where the Laravel API actually lives. No NEXT_PUBLIC_ prefix, so this stays
 * server-side and never ships to the browser.
 */
const API_ORIGIN = process.env.API_PROXY_TARGET ?? "http://localhost:8000";

const nextConfig: NextConfig = {
  /**
   * Same-origin proxy for the API, for local `next dev` parity.
   *
   * In production (prostartleasing.com), Nginx routes /api/* straight to
   * Laravel before it ever reaches this Next.js process, so this rewrite
   * never actually fires there — Laravel and Next.js run side by side on the
   * same server, and the backend's CORS config never comes into play. This
   * stays in place so `next dev` locally still proxies /api calls the same
   * way, without needing a separate CORS setup for local development.
   *
   * The app has no /api route of its own, so nothing is shadowed by this.
   */
  async rewrites() {
    return [
      {
        source: "/api/:path*",
        destination: `${API_ORIGIN}/api/:path*`,
      },
    ];
  },
};

export default nextConfig;
