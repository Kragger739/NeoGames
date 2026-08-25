import { FormEvent, useState } from "react";
import { Link } from "react-router-dom";

import { firstValidationError } from "../lib/errors";
import { levelProgress } from "../lib/leveling";
import { useAuthStore } from "../stores/authStore";

export function ProfilePage() {
  const host = useAuthStore((state) => state.host);
  const updateUsername = useAuthStore((state) => state.updateUsername);

  const [username, setUsername] = useState(host?.username ?? "");
  const [editing, setEditing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  if (!host) return null;

  const progress = levelProgress(host.xp, host.level);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await updateUsername(username);
      setEditing(false);
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="profile-page">
      <p>
        <Link to="/dashboard">← Dashboard</Link>
      </p>
      <h1>Your profile</h1>

      {editing ? (
        <form onSubmit={handleSubmit}>
          <label>
            Username
            <input
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              minLength={2}
              maxLength={24}
              required
            />
          </label>
          {error && <p className="form-error">{error}</p>}
          <div className="profile-edit-actions">
            <button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Save"}
            </button>
            <button
              type="button"
              className="button-secondary"
              onClick={() => {
                setEditing(false);
                setUsername(host.username ?? "");
                setError(null);
              }}
            >
              Cancel
            </button>
          </div>
        </form>
      ) : (
        <div className="profile-summary">
          <p className="profile-username">
            {host.username ?? host.name}
            <button
              type="button"
              className="button-secondary profile-edit-button"
              onClick={() => setEditing(true)}
            >
              Edit
            </button>
          </p>
        </div>
      )}

      <div className="level-card">
        <p className="level-label">Level {host.level}</p>
        <div className="level-bar">
          <div
            className="level-bar-fill"
            style={{ transform: `scaleX(${progress.percent / 100})` }}
          />
        </div>
        <p className="hint">
          {progress.xpIntoLevel} / {progress.xpForNextLevel} XP to level {host.level + 1}
        </p>
      </div>
    </div>
  );
}
