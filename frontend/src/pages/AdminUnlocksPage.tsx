import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";

import {
  type DailySong,
  type UnlockRow,
  useAdminUnlocksStore,
} from "../stores/adminUnlocksStore";
import { Button } from "../components/ui/Button";

const CATEGORY_LABELS: Record<UnlockRow["category"], string> = {
  game_night: "Game night",
  mode: "Modes",
  genre: "Genres",
};

const CATEGORY_ORDER: UnlockRow["category"][] = ["game_night", "mode", "genre"];

export function AdminUnlocksPage() {
  const { requirements, daily, status, error, fetch, setLevel, saveDaily, searchSongs } =
    useAdminUnlocksStore();

  useEffect(() => {
    void fetch();
  }, [fetch]);

  const grouped = useMemo(() => {
    const map = new Map<UnlockRow["category"], UnlockRow[]>();
    for (const row of requirements) {
      map.set(row.category, [...(map.get(row.category) ?? []), row]);
    }
    return map;
  }, [requirements]);

  // --- daily song editor local state ---
  const [picked, setPicked] = useState<DailySong[]>([]);
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<DailySong[]>([]);
  const [savingDaily, setSavingDaily] = useState(false);

  useEffect(() => {
    if (daily) setPicked(daily.songs);
  }, [daily]);

  useEffect(() => {
    if (query.trim().length < 2) {
      setResults([]);
      return;
    }
    let alive = true;
    const t = setTimeout(() => {
      void searchSongs(query).then((r) => {
        if (alive) setResults(r);
      });
    }, 250);
    return () => {
      alive = false;
      clearTimeout(t);
    };
  }, [query, searchSongs]);

  const dirty = useMemo(() => {
    if (!daily) return false;
    return JSON.stringify(picked.map((s) => s.id)) !== JSON.stringify(daily.songs.map((s) => s.id));
  }, [picked, daily]);

  async function handleSaveDaily() {
    setSavingDaily(true);
    await saveDaily(picked.map((s) => s.id));
    setSavingDaily(false);
  }

  return (
    <div className="admin-page">
      <p>
        <Link to="/admin">← Users</Link>
        {"  ·  "}
        <Link to="/admin/song-playlists">Song playlists</Link>
        {"  ·  "}
        <Link to="/admin/seasons">Seasons &amp; Battlepass</Link>
        {"  ·  "}
        <Link to="/">Home</Link>
      </p>
      <h1>Unlocks &amp; Daily</h1>

      {error && <p className="form-error">{error}</p>}
      {status !== "ready" && <p className="hint">Loading…</p>}

      <h2>Level requirements</h2>
      <p className="hint">
        The player level needed to host a game night, or to pick a mode / genre. Level 1 means no
        lock. Changes take effect immediately.
      </p>

      {CATEGORY_ORDER.map((category) => {
        const rows = grouped.get(category) ?? [];
        if (rows.length === 0) return null;
        return (
          <section key={category} className="admin-playlist-group">
            <h3>{CATEGORY_LABELS[category]}</h3>
            <ul className="player-list">
              {rows.map((row) => (
                <li key={row.key}>
                  <span className="friend-name">{row.label}</span>
                  <label className="admin-check">
                    Level
                    <input
                      type="number"
                      min={1}
                      max={999}
                      value={row.required_level}
                      onChange={(e) => {
                        const n = Math.max(1, Math.min(999, Number(e.target.value) || 1));
                        void setLevel(row.key, n);
                      }}
                    />
                  </label>
                </li>
              ))}
            </ul>
          </section>
        );
      })}

      <h2>Daily challenge</h2>
      {daily && (
        <p className="hint">
          {daily.date} · {daily.curated ? "curated" : "auto-generated"}
          {daily.has_attempts && " · players have already played today — changing it now is disruptive"}
        </p>
      )}

      <ol className="player-list">
        {picked.map((song, i) => (
          <li key={song.id}>
            <span className="friend-name">
              {i + 1}. {song.title} — {song.artist}
            </span>
            <Button
              variant="ghost"
              onClick={() => setPicked(picked.filter((s) => s.id !== song.id))}
            >
              Remove
            </Button>
          </li>
        ))}
      </ol>

      {picked.length < 5 && (
        <div className="admin-form">
          <label>
            Add a song
            <input
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search the pool by title or artist"
            />
          </label>
          {results.length > 0 && (
            <ul className="player-list">
              {results
                .filter((r) => !picked.some((p) => p.id === r.id))
                .map((r) => (
                  <li key={r.id}>
                    <span className="friend-name">
                      {r.title} — {r.artist}
                    </span>
                    <Button
                      onClick={() => {
                        setPicked([...picked, r]);
                        setQuery("");
                        setResults([]);
                      }}
                    >
                      Add
                    </Button>
                  </li>
                ))}
            </ul>
          )}
        </div>
      )}

      <Button onClick={() => void handleSaveDaily()} disabled={savingDaily || picked.length !== 5 || !dirty}>
        {savingDaily ? "Saving…" : `Save daily songs (${picked.length}/5)`}
      </Button>
    </div>
  );
}
