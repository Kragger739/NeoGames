import { type FormEvent, useState } from "react";
import { Link } from "react-router-dom";

import { firstValidationError } from "../../lib/errors";
import { useAuthStore } from "../../stores/authStore";
import { Button } from "../../components/ui/Button";
import { Card } from "../../components/ui/Card";

export function ForgotPasswordPage() {
  const requestPasswordReset = useAuthStore((state) => state.requestPasswordReset);

  const [email, setEmail] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await requestPasswordReset(email);
      setSent(true);
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="auth-page">
      <h1>Reset your password</h1>
      <Card className="auth-card">
        {sent ? (
          <p className="hint">
            If that address has an account, we've emailed a link to reset your
            password. The link expires in 60 minutes.
          </p>
        ) : (
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
            {error && <p className="form-error">{error}</p>}
            <Button type="submit" disabled={submitting}>
              {submitting ? "Sending…" : "Send reset link"}
            </Button>
          </form>
        )}
      </Card>
      <p>
        <Link to="/login">Back to log in</Link>
      </p>
    </div>
  );
}
