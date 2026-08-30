import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";

import { firstValidationError } from "../lib/errors";
import { useAdminPlaylistsStore } from "../stores/adminPlaylistsStore";
import { Button } from "../components/ui/Button";

const GENRE_LABELS: Record<string, string> = {
  normal: "Normal",
  pop: "Pop",
  hip_hop: "Hip-hop",
  german_rap: "German rap",
  classics: "Classics",
  year: "Year",
  iconic: "Iconic (Classic mode)",
};

export function AdminSongPlaylistsPage() {
  const { genres, playlists, poolSize, lastSync, progress, running, status, syncError, fetch, add, remove, startSync, stopSync } =
    useAdminPlaylistsStore();

  const [genre, setGenre] = useState("");
  const [playlist, setPlaylist] = useState("");
  const [label, setLabel] = useState("");
  const [fresh, setFresh] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    void fetch();
    return () => stopSync();
  }, [fetch, stopSync]);

  useEffect(() => {
    if (!genre && genres.length > 0) setGenre(genres[0]);
  }, [genres, genre]);

  const grouped = useMemo(() => {
    const map = new Map<string, typeof playlists>();
    for (const p of playlists) {
      map.set(p.genre, [...(map.get(p.genre) ?? []), p]);
    }
    return map;
  }, [playlists]);

  async function handleAdd(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    try {
      await add(genre, playlist.trim(), label.trim());
      setPlaylist("");
      setLabel("");
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="admin-page">
      <p>
        <Link to="/admin">← Users</Link>
        {"  ·  "}
        <Link to="/">Home</Link>
      </p>
      <h1>Song playlists</h1>
      <p className="hint">
        Curated <strong>public, user-made</strong> Spotify playlists that seed each genre&rsquo;s
        song pool. Spotify&rsquo;s own editorial playlists (Today&rsquo;s Top Hits, RapCaviar…)
        can&rsquo;t be read via the API. The sync runs right here in your browser — keep this tab
        open until it finishes (a large pool can take several minutes).
      </p>

      <div className="admin-sync-bar">
        <Button onClick={() => void startSync(fresh)} disabled={running}>
          {running ? "Syncing…" : "Sync now"}
        </Button>
        {running && (
          <Button variant="ghost" onClick={() => stopSync()}>
            Stop
          </Button>
        )}
        {!running && (
          <label className="admin-check">
            <input type="checkbox" checked={fresh} onChange={(e) => setFresh(e.target.checked)} />
            Replace the whole pool
          </label>
        )}
        <span className="hint">Pool: {poolSize} songs</span>
      </div>

      {syncError && <p className="form-error">{syncError}</p>}
      {progress && (
        <p className={progress.phase === "error" ? "form-error" : "hint"}>
          {progress.phase === "prepare" &&
            `Reading playlists ${progress.prepared_count} / ${progress.total_playlists}…`}
          {progress.phase === "seed" &&
            `Adding songs ${progress.seeded + progress.skipped} / ${progress.total_items} (${progress.seeded} added, ${progress.skipped} had no preview)…`}
          {progress.phase === "done" &&
            `Done — ${progress.summary}. Pool now holds ${progress.pool_size} songs.`}
          {progress.phase === "error" && `Sync failed: ${progress.error}`}
        </p>
      )}
      {!progress && lastSync && (
        <p className="hint">
          {lastSync.state === "done"
            ? `Last sync ${new Date(lastSync.at).toLocaleString()} — ${lastSync.summary}.`
            : lastSync.state === "error"
              ? `Last sync failed: ${lastSync.summary}`
              : "A sync is in progress."}
        </p>
      )}
      {!progress && !lastSync && <p className="hint">Never synced.</p>}

      <form className="admin-form" onSubmit={handleAdd}>
        <label>
          Genre
          <select value={genre} onChange={(e) => setGenre(e.target.value)}>
            {genres.map((g) => (
              <option key={g} value={g}>
                {GENRE_LABELS[g] ?? g}
              </option>
            ))}
          </select>
        </label>
        <label>
          Spotify playlist link or id
          <input
            type="text"
            value={playlist}
            onChange={(e) => setPlaylist(e.target.value)}
            placeholder="https://open.spotify.com/playlist/…"
            required
          />
        </label>
        <label>
          Label (optional)
          <input
            type="text"
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            placeholder="e.g. 2010s throwbacks"
          />
        </label>
        {error && <p className="form-error">{error}</p>}
        <Button type="submit" disabled={busy || !genre}>
          {busy ? "Adding…" : "Add playlist"}
        </Button>
      </form>

      {status !== "ready" && playlists.length === 0 ? (
        <p className="hint">Loading…</p>
      ) : playlists.length === 0 ? (
        <p className="hint">No playlists yet — add one above.</p>
      ) : (
        [...grouped.entries()].map(([g, rows]) => (
          <section key={g} className="admin-playlist-group">
            <h2>{GENRE_LABELS[g] ?? g}</h2>
            <ul className="player-list">
              {rows.map((p) => (
                <li key={p.id}>
                  <span className="friend-name">
                    <a
                      href={`https://open.spotify.com/playlist/${p.spotify_playlist_id}`}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      {p.label ?? p.spotify_playlist_id}
                    </a>
                  </span>
                  <Button variant="ghost" onClick={() => void remove(p.id)}>
                    Remove
                  </Button>
                </li>
              ))}
            </ul>
          </section>
        ))
      )}
    </div>
  );
}
