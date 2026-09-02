import type { NextConfig } from "next";

/**
 * Where the Laravel API actually lives. No NEXT_PUBLIC_ prefix, so this stays
 * server-side and never ships to the browser.
 */
const API_ORIGIN = process.env.API_PROXY_TARGET ?? "https://blue-pheasant-283511.hostingersite.com";

const nextConfig: NextConfig = {
  /**
   * Same-origin proxy for the API.
   *
   * The browser calls /api/... on this Vercel domain and Next forwards it to
   * Laravel server-side, so no cross-origin request is ever made and CORS
   * never applies. That matters because Vercel mints a fresh deployment URL on
   * every `vercel --prod`, while the backend's allowed origin is a fixed value
   * in its own .env — pointing the browser straight at Laravel breaks again on
   * each deploy. Routing through here means the backend only ever sees a
   * server-to-server call and its CORS config stops mattering entirely.
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
