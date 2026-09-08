import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";

import { firstValidationError } from "../lib/errors";
import {
  genreLabel,
  useAdminSongsStore,
  type AdminSong,
  type SongPoolFilter,
} from "../stores/adminSongsStore";
import { AdminNav } from "../components/AdminNav";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";

export function AdminSongsPage() {
  const {
    songs,
    meta,
    genres,
    status,
    search,
    genre,
    poolFilter,
    page,
    selectedIds,
    fetchSongs,
    setSearch,
    setGenre,
    setPoolFilter,
    setPage,
    updateSong,
    bulkExclude,
    toggleSelected,
    clearSelection,
  } = useAdminSongsStore();

  const [term, setTerm] = useState(search);
  const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [bulkBusy, setBulkBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void fetchSongs();
  }, [fetchSongs]);

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

  async function toggleExcluded(song: AdminSong) {
    setError(null);
    setBusyId(song.id);
    try {
      await updateSong(song.id, { excluded: !song.excluded });
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setBusyId(null);
    }
  }

  async function runBulk(excluded: boolean) {
    setError(null);
    setBulkBusy(true);
    try {
      await bulkExclude(selectedIds, excluded);
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setBulkBusy(false);
    }
  }

  return (
    <div className="admin-page">
      <AdminNav />
      <h1>Songs</h1>
      <p className="hint">
        Every track in the pool. “Remove” sets the reversible <code>excluded</code> flag — it
        keeps historical rounds intact and survives the weekly sync. Metadata edits may be
        overwritten by the next sync.
      </p>

      <div className="admin-sync-bar">
        <input
          type="search"
          placeholder="Search title or artist"
          value={term}
          onChange={(e) => onTermChange(e.target.value)}
          className="admin-search admin-song-filter-search"
        />
        <select
          className="admin-song-select"
          value={genre}
          onChange={(e) => setGenre(e.target.value)}
        >
          <option value="">All genres</option>
          {genres.map((g) => (
            <option key={g ?? "unknown"} value={g ?? "unknown"}>
              {genreLabel(g)}
            </option>
          ))}
        </select>
        <select
          className="admin-song-select"
          value={poolFilter}
          onChange={(e) => setPoolFilter(e.target.value as SongPoolFilter)}
        >
          <option value="all">In pool + removed</option>
          <option value="in_pool">In pool only</option>
          <option value="removed">Removed only</option>
        </select>
      </div>

      {error && <p className="form-error">{error}</p>}

      {selectedIds.length > 0 && (
        <div className="admin-sync-bar admin-song-bulk">
          <span className="hint">{selectedIds.length} selected</span>
          <Button variant="danger" disabled={bulkBusy} onClick={() => void runBulk(true)}>
            Remove {selectedIds.length} from pool
          </Button>
          <Button variant="ghost" disabled={bulkBusy} onClick={() => void runBulk(false)}>
            Restore {selectedIds.length}
          </Button>
          <Button variant="ghost" disabled={bulkBusy} onClick={clearSelection}>
            Clear
          </Button>
        </div>
      )}

      {status !== "ready" && songs.length === 0 ? (
        <p className="hint">Loading…</p>
      ) : songs.length === 0 ? (
        <p className="hint">No songs match these filters.</p>
      ) : (
        <ul className="player-list admin-song-list">
          {songs.map((song) => (
            <li
              key={song.id}
              className={song.excluded ? "admin-song-row is-removed" : "admin-song-row"}
            >
              <input
                type="checkbox"
                className="admin-song-check"
                checked={selectedIds.includes(song.id)}
                onChange={() => toggleSelected(song.id)}
                aria-label={`Select ${song.title}`}
              />
              <Link to={`/admin/songs/${song.id}`} className="admin-song-main">
                {song.album_art_url ? (
                  <img
                    className="admin-song-art"
                    src={song.album_art_url}
                    alt=""
                    width={36}
                    height={36}
                  />
                ) : (
                  <span className="admin-song-art art-placeholder" aria-hidden="true" />
                )}
                <span className="admin-song-identity">
                  <strong>{song.title}</strong>
                  <span className="hint">{song.artist}</span>
                </span>
              </Link>
              <span className="admin-song-tags">
                {song.genre && <Badge tone="grape">{genreLabel(song.genre)}</Badge>}
                <span className="hint">
                  pop {song.popularity}
                  {song.release_year ? ` · ${song.release_year}` : ""}
                </span>
                {song.excluded && <Badge tone="coral">Removed</Badge>}
              </span>
              <Button
                variant={song.excluded ? "ghost" : "danger"}
                disabled={busyId === song.id}
                onClick={() => void toggleExcluded(song)}
              >
                {song.excluded ? "Restore" : "Remove"}
              </Button>
            </li>
          ))}
        </ul>
      )}

      {meta && (
        <div className="admin-pagination">
          <Button
            variant="ghost"
            disabled={page <= 1}
            hidden={meta.last_page <= 1}
            onClick={() => setPage(page - 1)}
          >
            Previous
          </Button>
          <span className="hint">
            {meta.last_page > 1 ? `Page ${meta.current_page} of ${meta.last_page} · ` : ""}
            {meta.total} songs · {meta.pool_size} in pool · {meta.removed_count} removed
          </span>
          <Button
            variant="ghost"
            disabled={page >= meta.last_page}
            hidden={meta.last_page <= 1}
            onClick={() => setPage(page + 1)}
          >
            Next
          </Button>
        </div>
      )}
    </div>
  );
}
