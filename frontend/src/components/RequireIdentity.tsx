import { type PropsWithChildren, useEffect } from "react";

import { ensureCsrfCookie } from "../lib/api";
import { useAuthStore } from "../stores/authStore";

/**
 * Renders children once the auth status resolves, with NO redirect - `host`
 * may be null (anonymous; the server mints a hidden guest row on the first
 * play action), a guest, or a real account. Used on the always-open surfaces
 * (Home, Songle, DDF). Account-only pages use RequireHost instead.
 */
export function RequireIdentity({ children }: PropsWithChildren) {
  const { status, fetchHost } = useAuthStore();

  useEffect(() => {
    if (status === "idle") {
      // Guarantee the XSRF-TOKEN cookie before the first guest-minting POST -
      // SonglePage / DdfLandingPage POST straight to /api/daily/start etc.
      // without their own ensureCsrfCookie().
      void ensureCsrfCookie().finally(() => void fetchHost());
    }
  }, [status, fetchHost]);

  if (status !== "ready") {
    return <p>Loading…</p>;
  }

  return <>{children}</>;
}
