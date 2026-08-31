import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";

import { EMPTY_AVATAR } from "../lib/avatarData";
import { useAdminStore } from "../stores/adminStore";
import { Avatar } from "../components/ui/Avatar";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";

export function AdminUsersPage() {
  const { users, meta, status, search, page, fetchUsers, setSearch, setPage } = useAdminStore();
  const [term, setTerm] = useState(search);
  const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    void fetchUsers();
  }, [fetchUsers]);

  useEffect(() => {
    return () => {
      if (debounce.current) clearTimeout(debounce.current);
    };
  }, []);

  function onTermChange(value: string) {
    setTerm(value);
    if (debounce.current) clearTimeout(debounce.current);
    debounce.current = setTimeout(() => setSearch(value.trim()), 300);
  }

  return (
    <div className="admin-page">
      <p>
        <Link to="/">← Home</Link>
        {"  ·  "}
        <Link to="/admin/song-playlists">Song playlists</Link>
        {"  ·  "}
        <Link to="/admin/unlocks">Unlocks &amp; Daily</Link>
        {"  ·  "}
        <Link to="/admin/seasons">Seasons &amp; Battlepass</Link>
      </p>
      <h1>Users</h1>

      <input
        type="search"
        placeholder="Search name, username, or email"
        value={term}
        onChange={(e) => onTermChange(e.target.value)}
        className="admin-search"
      />

      {status !== "ready" && users.length === 0 ? (
        <p className="hint">Loading…</p>
      ) : users.length === 0 ? (
        <p className="hint">No users match “{search}”.</p>
      ) : (
        <ul className="player-list admin-user-list">
          {users.map((user) => (
            <li key={user.id}>
              <Link to={`/admin/users/${user.id}`} className="admin-user-row">
                <Avatar data={user.avatar ?? EMPTY_AVATAR} size="sm" animated={false} />
                <span className="admin-user-identity">
                  <strong>{user.username ?? user.name}</strong>
                  <span className="hint">{user.email}</span>
                </span>
                <span className="admin-user-tags">
                  {user.is_admin && <Badge tone="grape">Admin</Badge>}
                  {user.banned_at && <Badge tone="coral">Banned</Badge>}
                  {!user.email_verified && <Badge tone="sunflower">Unverified</Badge>}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}

      {meta && meta.last_page > 1 && (
        <div className="admin-pagination">
          <Button variant="ghost" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            Previous
          </Button>
          <span className="hint">
            Page {meta.current_page} of {meta.last_page} · {meta.total} users
          </span>
          <Button
            variant="ghost"
            disabled={page >= meta.last_page}
            onClick={() => setPage(page + 1)}
          >
            Next
          </Button>
        </div>
      )}
    </div>
  );
}
