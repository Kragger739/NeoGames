import { type FormEvent, useEffect, useState } from "react";
import { Navigate, useNavigate } from "react-router-dom";

import { firstValidationError } from "../../lib/errors";
import { useAuthStore } from "../../stores/authStore";
import { Button } from "../../components/ui/Button";
import { Card } from "../../components/ui/Card";

export function VerifyEmailPage() {
  const host = useAuthStore((state) => state.host);
  const status = useAuthStore((state) => state.status);
  const fetchHost = useAuthStore((state) => state.fetchHost);
  const verifyEmail = useAuthStore((state) => state.verifyEmail);
  const resendVerificationCode = useAuthStore((state) => state.resendVerificationCode);
  const logout = useAuthStore((state) => state.logout);
  const navigate = useNavigate();

  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  // Timestamp (ms) the resend button becomes available again, or 0.
  const [resendAt, setResendAt] = useState(0);
  const [now, setNow] = useState(() => Date.now());
  const cooldown = Math.max(0, Math.ceil((resendAt - now) / 1000));

  useEffect(() => {
    if (status === "idle") {
      void fetchHost();
    }
  }, [status, fetchHost]);

  useEffect(() => {
    if (resendAt <= Date.now()) return;
    const id = setInterval(() => setNow(Date.now()), 500);
    return () => clearInterval(id);
  }, [resendAt]);

  if (status !== "ready") {
    return <p>Loading…</p>;
  }
  if (!host) {
    return <Navigate to="/login" replace />;
  }
  if (host.email_verified) {
    return <Navigate to="/" replace />;
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setNotice(null);
    setSubmitting(true);
    try {
      await verifyEmail(code.trim());
      navigate("/");
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setSubmitting(false);
    }
  }

  async function handleResend() {
    setError(null);
    setNotice(null);
    try {
      await resendVerificationCode();
      setNotice("Sent — check your inbox.");
      setResendAt(Date.now() + 60_000);
    } catch (err) {
      const retryAfter = (err as { response?: { data?: { retry_after?: number } } })
        .response?.data?.retry_after;
      if (typeof retryAfter === "number") {
        setResendAt(Date.now() + retryAfter * 1000);
        setError(`Please wait ${retryAfter}s before requesting another code.`);
      } else {
        setError(firstValidationError(err));
      }
    }
  }

  async function handleLogout() {
    await logout();
    navigate("/login");
  }

  return (
    <div className="auth-page">
      <h1>Check your email</h1>
      <p className="hint">
        We sent a 6-digit code to <strong>{host.email}</strong>. Enter it below to
        activate your account.
      </p>
      <Card className="auth-card">
        <form onSubmit={handleSubmit}>
          <label>
            Verification code
            <input
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))}
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              placeholder="000000"
              required
            />
          </label>
          {error && <p className="form-error">{error}</p>}
          {notice && <p className="hint">{notice}</p>}
          <Button type="submit" disabled={submitting || code.length !== 6}>
            {submitting ? "Verifying…" : "Verify email"}
          </Button>
        </form>
        <p className="auth-divider">didn't get it?</p>
        <Button variant="ghost" onClick={handleResend} disabled={cooldown > 0}>
          {cooldown > 0 ? `Resend code (${cooldown}s)` : "Resend code"}
        </Button>
        <Button variant="ghost" onClick={handleLogout}>
          Wrong account? Log out
        </Button>
      </Card>
    </div>
  );
}
