import { type FormEvent, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";

import { firstValidationError } from "../../lib/errors";
import { useAuthStore } from "../../stores/authStore";
import { Button } from "../../components/ui/Button";
import { Card } from "../../components/ui/Card";

export function ResetPasswordPage() {
  const resetPassword = useAuthStore((state) => state.resetPassword);
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  const token = searchParams.get("token") ?? "";
  const email = searchParams.get("email") ?? "";

  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const linkValid = token !== "" && email !== "";

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await resetPassword({ token, email, password, passwordConfirmation });
      navigate("/login", { replace: true });
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="auth-page">
      <h1>Choose a new password</h1>
      <Card className="auth-card">
        {!linkValid ? (
          <p className="form-error">
            This reset link is missing information. Request a new one from the
            forgot-password page.
          </p>
        ) : (
          <form onSubmit={handleSubmit}>
            <label>
              Email
              <input type="email" value={email} readOnly />
            </label>
            <label>
              New password
              <input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
              />
            </label>
            <label>
              Confirm new password
              <input
                type="password"
                value={passwordConfirmation}
                onChange={(e) => setPasswordConfirmation(e.target.value)}
                required
              />
            </label>
            {error && <p className="form-error">{error}</p>}
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Reset password"}
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
