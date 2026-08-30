import { useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";

import { firstValidationError } from "../lib/errors";
import { useAdminStore } from "../stores/adminStore";
import { useAuthStore } from "../stores/authStore";
import { Button } from "../components/ui/Button";

const EMPTY_FORM = {
  name: "",
  username: "",
  email: "",
  emailVerified: false,
  isAdmin: false,
};

export function AdminUserDetailPage() {
  const { id } = useParams();
  const userId = Number(id);
  const navigate = useNavigate();

  const host = useAuthStore((state) => state.host);
  const { selected, selectedStatus, fetchUser, updateUser, deleteUser, banUser, unbanUser, resetXp } =
    useAdminStore();

  const [form, setForm] = useState(EMPTY_FORM);
  const [banReason, setBanReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirm, setConfirm] = useState<"delete" | "resetXp" | null>(null);

  useEffect(() => {
    if (Number.isFinite(userId)) void fetchUser(userId);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- fetchUser is a stable zustand action; re-run only when the :id param changes
  }, [userId]);

  // Sync the local edit form whenever the store's selected user changes
  // (initial load, and after each successful mutation refreshes it). This
  // is the "mirror an external store into local fields" pattern - the one
  // set-state-in-effect the linter flags here, same as CosmeticsPage.
  useEffect(() => {
    if (!selected) return;
    setForm({
      name: selected.name,
      username: selected.username ?? "",
      email: selected.email,
      emailVerified: selected.email_verified,
      isAdmin: selected.is_admin,
    });
  }, [selected]);

  if (selectedStatus !== "ready" || !selected) {
    return (
      <div className="admin-page">
        <p>
          <Link to="/admin">← Users</Link>
        </p>
        <p className="hint">Loading…</p>
      </div>
    );
  }

  const user = selected;
  const isSelf = host?.id === user.id;

  async function run(fn: () => Promise<unknown>, ok: string) {
    setError(null);
    setNotice(null);
    setBusy(true);
    try {
      await fn();
      setNotice(ok);
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  }

  function onSave(e: FormEvent) {
    e.preventDefault();
    void run(
      () =>
        updateUser(user.id, {
          name: form.name,
          username: form.username,
          email: form.email,
          email_verified: form.emailVerified,
          is_admin: form.isAdmin,
        }),
      "Saved.",
    );
  }

  return (
    <div className="admin-page">
      <p>
        <Link to="/admin">← Users</Link>
      </p>
      <h1>{user.username ?? user.name}</h1>
      <p className="hint">
        Joined {user.created_at?.slice(0, 10) ?? "—"} · Level {user.level} · {user.xp} XP
        {user.provider ? ` · ${user.provider} account` : ""}
      </p>

      {error && <p className="form-error">{error}</p>}
      {notice && <p className="hint">{notice}</p>}

      <form onSubmit={onSave} className="admin-form">
        <label>
          Name
          <input
            type="text"
            value={form.name}
            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
            required
          />
        </label>
        <label>
          Username
          <input
            type="text"
            value={form.username}
            onChange={(e) => setForm((f) => ({ ...f, username: e.target.value }))}
            required
          />
        </label>
        <label>
          Email
          <input
            type="email"
            value={form.email}
            onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
            required
          />
        </label>
        <label className="admin-check">
          <input
            type="checkbox"
            checked={form.emailVerified}
            onChange={(e) => setForm((f) => ({ ...f, emailVerified: e.target.checked }))}
          />
          Email verified
        </label>
        <label className="admin-check">
          <input
            type="checkbox"
            checked={form.isAdmin}
            disabled={isSelf}
            title={isSelf ? "You can't change your own admin status" : undefined}
            onChange={(e) => setForm((f) => ({ ...f, isAdmin: e.target.checked }))}
          />
          Admin{isSelf ? " (can't change your own)" : ""}
        </label>
        <Button type="submit" disabled={busy}>
          Save changes
        </Button>
      </form>

      <section className="admin-moderation">
        <h2>Moderation</h2>

        {user.banned_at ? (
          <div>
            <p className="hint">Banned{user.ban_reason ? ` — ${user.ban_reason}` : ""}.</p>
            <Button
              variant="ghost"
              disabled={busy}
              onClick={() => void run(() => unbanUser(user.id), "Unbanned.")}
            >
              Unban
            </Button>
          </div>
        ) : (
          <div className="admin-ban-box">
            <input
              type="text"
              placeholder="Reason (optional)"
              value={banReason}
              onChange={(e) => setBanReason(e.target.value)}
              disabled={isSelf}
            />
            <Button
              variant="danger"
              disabled={busy || isSelf}
              title={isSelf ? "You can't ban your own account" : undefined}
              onClick={() => void run(() => banUser(user.id, banReason), "Banned.")}
            >
              {isSelf ? "Can't ban yourself" : "Ban user"}
            </Button>
          </div>
        )}

        <div>
          <Button
            variant="ghost"
            disabled={busy}
            onClick={() => {
              if (confirm === "resetXp") {
                void run(() => resetXp(user.id), "XP reset.");
              } else {
                setConfirm("resetXp");
              }
            }}
          >
            {confirm === "resetXp" ? "Click again to confirm reset" : "Reset XP"}
          </Button>
        </div>

        <div>
          <Button
            variant="danger"
            disabled={busy || isSelf}
            title={isSelf ? "You can't delete your own account" : undefined}
            onClick={() => {
              if (isSelf) return;
              if (confirm === "delete") {
                void run(async () => {
                  await deleteUser(user.id);
                  navigate("/admin");
                }, "Deleted.");
              } else {
                setConfirm("delete");
              }
            }}
          >
            {isSelf
              ? "Can't delete yourself"
              : confirm === "delete"
                ? "Click again to permanently delete"
                : "Delete user"}
          </Button>
        </div>
      </section>
    </div>
  );
}
