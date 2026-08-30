import { type FormEvent, useEffect, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";

import { firstValidationError } from "../../lib/errors";
import { oauthRedirectUrl } from "../../lib/oauth";
import { useAuthStore } from "../../stores/authStore";
import { Button } from "../../components/ui/Button";
import { Card } from "../../components/ui/Card";
import { DiscordMark } from "../../components/icons/DiscordMark";
import { GoogleMark } from "../../components/icons/GoogleMark";

export function RegisterPage() {
  const register = useAuthStore((state) => state.register);
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [acceptedTerms, setAcceptedTerms] = useState(false);
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
      await register(name, email, password, passwordConfirmation, acceptedTerms);
      navigate("/verify-email");
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="auth-page">
      <h1>Let's get the party started</h1>
      <p className="hint">Create a host account to start game nights.</p>
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
            Name
            <input value={name} onChange={(e) => setName(e.target.value)} required />
          </label>
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
          <label>
            Confirm password
            <input
              type="password"
              value={passwordConfirmation}
              onChange={(e) => setPasswordConfirmation(e.target.value)}
              required
            />
          </label>
          <label className="checkbox-label">
            <input
              type="checkbox"
              checked={acceptedTerms}
              onChange={(e) => setAcceptedTerms(e.target.checked)}
            />
            <span>
              I agree to the{" "}
              <Link to="/terms" target="_blank" rel="noreferrer">Terms of Service</Link>{" "}
              and{" "}
              <Link to="/privacy" target="_blank" rel="noreferrer">Privacy Policy</Link>.
            </span>
          </label>
          {error && <p className="form-error">{error}</p>}
          <Button type="submit" disabled={submitting || !acceptedTerms}>
            {submitting ? "Creating account…" : "Create account"}
          </Button>
        </form>
      </Card>
      <p>
        Already a host? <Link to="/login">Log in</Link>
      </p>
    </div>
  );
}
