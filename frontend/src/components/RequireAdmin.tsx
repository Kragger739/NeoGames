import { type PropsWithChildren, useEffect } from "react";
import { Navigate } from "react-router-dom";

import { useAuthStore } from "../stores/authStore";

export function RequireAdmin({ children }: PropsWithChildren) {
  const { host, status, fetchHost } = useAuthStore();

  useEffect(() => {
    if (status === "idle") {
      void fetchHost();
    }
  }, [status, fetchHost]);

  if (status !== "ready") {
    return <p>Loading…</p>;
  }

  if (!host) {
    return <Navigate to="/login" replace />;
  }

  if (!host.email_verified) {
    return <Navigate to="/verify-email" replace />;
  }

  if (!host.is_admin) {
    return <Navigate to="/" replace />;
  }

  return <>{children}</>;
}
