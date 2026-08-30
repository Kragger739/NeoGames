import { type FormEvent, useEffect, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";

import { firstValidationError } from "../../lib/errors";
import { oauthRedirectUrl } from "../../lib/oauth";
import { useAuthStore } from "../../stores/authStore";
import { Button } from "../../components/ui/Button";
import { Card } from "../../components/ui/Card";
import { DiscordMark } from "../../components/icons/DiscordMark";
import { GoogleMark } from "../../components/icons/GoogleMark";

export function LoginPage() {
  const login = useAuthStore((state) => state.login);
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (searchParams.get("error") === "oauth_failed") {
      setError("That sign-in didn't work — please try again.");
    }
  }, [searchParams]);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await login(email, password);
      navigate(useAuthStore.getState().host?.email_verified ? "/" : "/verify-email");
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="auth-page">
      <h1>Welcome back</h1>
      <p className="hint">Log in to host the next round.</p>
      <Card className="auth-card">
        <div className="oauth-buttons">
          <Button variant="ghost" onClick={() => { window.location.href = oauthRedirectUrl("google"); }}>
            <GoogleMark />
            Continue with Google
          </Button>
          <Button variant="ghost" onClick={() => { window.location.href = oauthRedirectUrl("discord"); }}>
            <DiscordMark />
            Continue with Discord
          </Button>
        </div>
        <p className="auth-divider">or continue with email</p>
        <form onSubmit={handleSubmit}>
          <label>
            Email
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </label>
          <label>
            Password
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </label>
          {error && <p className="form-error">{error}</p>}
          <Button type="submit" disabled={submitting}>
            {submitting ? "Logging in…" : "Log in"}
          </Button>
        </form>
        <p className="hint auth-forgot">
          <Link to="/forgot-password">Forgot your password?</Link>
        </p>
      </Card>
      <p>
        Need an account? <Link to="/register">Register</Link>
      </p>
    </div>
  );
}
