import { Link, useLocation } from "react-router-dom";

import { useAuthStore } from "../stores/authStore";

// Auth screens shouldn't nag - the user is already on the way to an account.
const HIDDEN_PREFIXES = ["/login", "/register", "/verify-email", "/forgot-password", "/reset-password"];

/**
 * A thin persistent strip shown only while playing as a guest, prompting the
 * upgrade to a real account. Mounted app-wide so it also rides along on the
 * immersive results screen after a guest finishes a Daily.
 */
export function GuestBanner() {
  const host = useAuthStore((state) => state.host);
  const { pathname } = useLocation();

  if (!host?.is_guest) {
    return null;
  }

  if (HIDDEN_PREFIXES.some((prefix) => pathname.startsWith(prefix))) {
    return null;
  }

  return (
    <div className="guest-banner">
      <span>Playing as a guest — your XP, levels and NeoCoins won't be saved.</span>
      <Link to="/register">Create a free account</Link>
    </div>
  );
}
