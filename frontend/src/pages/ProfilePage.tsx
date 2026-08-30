import { useRef, useState, type ChangeEvent, type FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";

import { firstValidationError } from "../lib/errors";
import { levelProgress } from "../lib/leveling";
import { useAuthStore } from "../stores/authStore";
import { useThemeStore, type ThemePref } from "../stores/themeStore";
import { Avatar } from "../components/ui/Avatar";
import { Button } from "../components/ui/Button";

const THEME_OPTIONS: { value: ThemePref; label: string }[] = [
  { value: "system", label: "System" },
  { value: "light", label: "Light" },
  { value: "dark", label: "Dark" },
];

export function ProfilePage() {
  const host = useAuthStore((state) => state.host);
  const navigate = useNavigate();
  const updateUsername = useAuthStore((state) => state.updateUsername);
  const uploadAvatar = useAuthStore((state) => state.uploadAvatar);
  const removeAvatar = useAuthStore((state) => state.removeAvatar);
  const deleteAccount = useAuthStore((state) => state.deleteAccount);
  const themePref = useThemeStore((state) => state.pref);
  const setThemePref = useThemeStore((state) => state.setPref);

  const isOAuthAccount = Boolean(host?.provider);
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const [deleteSecret, setDeleteSecret] = useState("");
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState(false);

  const [username, setUsername] = useState(host?.username ?? "");
  const [editing, setEditing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [avatarError, setAvatarError] = useState<string | null>(null);
  const [avatarBusy, setAvatarBusy] = useState(false);
  const avatarInputRef = useRef<HTMLInputElement>(null);

  if (!host) return null;

  async function handleAvatarChange(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;

    setAvatarError(null);
    setAvatarBusy(true);
    try {
      await uploadAvatar(file);
    } catch (err) {
      setAvatarError(firstValidationError(err));
    } finally {
      setAvatarBusy(false);
    }
  }

  async function handleRemoveAvatar() {
    setAvatarError(null);
    setAvatarBusy(true);
    try {
      await removeAvatar();
    } catch (err) {
      setAvatarError(firstValidationError(err));
    } finally {
      setAvatarBusy(false);
    }
  }

  const progress = levelProgress(host.xp, host.level);

  async function handleDeleteAccount(e: FormEvent) {
    e.preventDefault();
    setDeleteError(null);
    setDeleting(true);
    try {
      await deleteAccount(deleteSecret);
      navigate("/");
    } catch (err) {
      setDeleteError(firstValidationError(err));
      setDeleting(false);
    }
  }

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
        <Link to="/">← Home</Link>
      </p>
      <h1>Your profile</h1>

      <div className="avatar-section">
        <Avatar data={host.avatar} size="lg" />
        <input
          ref={avatarInputRef}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          onChange={(e) => void handleAvatarChange(e)}
          hidden
        />
        <div className="avatar-actions">
          <Button variant="grape" onClick={() => navigate("/profile/cosmetics")}>
            Customize look
          </Button>
          <Button
            variant="ghost"
            disabled={avatarBusy}
            onClick={() => avatarInputRef.current?.click()}
          >
            {avatarBusy ? "Working…" : host.avatar_url ? "Change photo" : "Add a photo"}
          </Button>
          {host.avatar_url && (
            <Button variant="ghost" disabled={avatarBusy} onClick={() => void handleRemoveAvatar()}>
              Remove
            </Button>
          )}
        </div>
        {avatarError && <p className="form-error">{avatarError}</p>}
      </div>

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
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Save"}
            </Button>
            <Button
              variant="ghost"
              onClick={() => {
                setEditing(false);
                setUsername(host.username ?? "");
                setError(null);
              }}
            >
              Cancel
            </Button>
          </div>
        </form>
      ) : (
        <div className="profile-summary">
          <p className="profile-username">
            {host.username ?? host.name}
            <Button variant="ghost" className="profile-edit-button" onClick={() => setEditing(true)}>
              Edit
            </Button>
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

      <section className="theme-section">
        <h2>Appearance</h2>
        <p className="hint">
          Dark mode follows your device while set to System.
        </p>
        <div className="theme-toggle" role="group" aria-label="Theme">
          {THEME_OPTIONS.map((opt) => (
            <button
              key={opt.value}
              type="button"
              className={
                "theme-toggle-option" +
                (themePref === opt.value ? " is-active" : "")
              }
              aria-pressed={themePref === opt.value}
              onClick={() => setThemePref(opt.value)}
            >
              {opt.label}
            </button>
          ))}
        </div>
      </section>

      <section className="danger-zone">
        <h2>Delete account</h2>
        <p className="hint">
          Permanently deletes your account, profile, friends, hosted rooms and
          Workshop content. This cannot be undone.
        </p>
        {!confirmingDelete ? (
          <Button variant="danger" onClick={() => setConfirmingDelete(true)}>
            Delete my account
          </Button>
        ) : (
          <form onSubmit={handleDeleteAccount}>
            <label>
              {isOAuthAccount
                ? `Type your username (${host.username ?? host.name}) to confirm`
                : "Enter your password to confirm"}
              <input
                type={isOAuthAccount ? "text" : "password"}
                value={deleteSecret}
                onChange={(e) => setDeleteSecret(e.target.value)}
                autoComplete={isOAuthAccount ? "off" : "current-password"}
                required
              />
            </label>
            {deleteError && <p className="form-error">{deleteError}</p>}
            <div className="profile-edit-actions">
              <Button type="submit" variant="danger" disabled={deleting || !deleteSecret}>
                {deleting ? "Deleting…" : "Permanently delete"}
              </Button>
              <Button
                variant="ghost"
                onClick={() => {
                  setConfirmingDelete(false);
                  setDeleteSecret("");
                  setDeleteError(null);
                }}
              >
                Cancel
              </Button>
            </div>
          </form>
        )}
      </section>
    </div>
  );
}
