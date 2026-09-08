import { useEffect, useState, type FormEvent } from "react";
import { Link, useParams } from "react-router-dom";

import { firstValidationError } from "../lib/errors";
import { useAdminSongsStore } from "../stores/adminSongsStore";
import { AdminNav } from "../components/AdminNav";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";

const GENRE_OPTIONS = [
  { value: "", label: "— none —" },
  { value: "pop", label: "Pop" },
  { value: "hip_hop", label: "Hip hop" },
  { value: "german_rap", label: "German rap" },
  { value: "iconic", label: "Iconic" },
];

const EMPTY_FORM = {
  title: "",
  artist: "",
  genre: "",
  popularity: "0",
  releaseYear: "",
};

export function AdminSongDetailPage() {
  const { id } = useParams();
  const songId = Number(id);

  const { selected, selectedStatus, fetchSong, updateSong } = useAdminSongsStore();

  const [form, setForm] = useState(EMPTY_FORM);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (Number.isFinite(songId)) void fetchSong(songId);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- fetchSong is a stable zustand action; re-run only when :id changes
  }, [songId]);

  // Mirror the store's selected song into the local edit fields (initial
  // load + after each save refreshes it).
  useEffect(() => {
    if (!selected) return;
    setForm({
      title: selected.title,
      artist: selected.artist,
      genre: selected.genre ?? "",
      popularity: String(selected.popularity),
      releaseYear: selected.release_year != null ? String(selected.release_year) : "",
    });
  }, [selected]);

  if (!Number.isFinite(songId)) {
    return (
      <div className="admin-page">
        <AdminNav />
        <p className="hint">Song not found.</p>
      </div>
    );
  }

  if (selectedStatus !== "ready" || !selected) {
    return (
      <div className="admin-page">
        <AdminNav />
        <p className="hint">Loading…</p>
      </div>
    );
  }

  const song = selected;

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
    }
  }

  function onSave(e: FormEvent) {
    e.preventDefault();
    void run(
      () =>
        updateSong(song.id, {
          title: form.title.trim(),
          artist: form.artist.trim(),
          genre: form.genre === "" ? null : form.genre,
          popularity: Number(form.popularity),
          release_year: form.releaseYear.trim() === "" ? null : Number(form.releaseYear),
        }),
      "Saved.",
    );
  }

  return (
    <div className="admin-page">
      <AdminNav />
      <p className="hint">
        <Link to="/admin/songs">← Back to songs</Link>
      </p>
      <h1>
        {song.title}
        {song.excluded && (
          <>
            {" "}
            <Badge tone="coral">Removed</Badge>
          </>
        )}
      </h1>

      <div className="admin-song-detail-head">
        {song.album_art_url ? (
          <img
            className="admin-song-detail-art"
            src={song.album_art_url}
            alt=""
            width={96}
            height={96}
          />
        ) : (
          <span className="admin-song-detail-art art-placeholder" aria-hidden="true" />
        )}
        <div>
          <p className="hint">Track id: {song.provider_track_id}</p>
          <p className="hint">
            Last used: {song.last_used_at ? song.last_used_at.slice(0, 10) : "never"}
          </p>
          {song.preview_url && (
            // eslint-disable-next-line jsx-a11y/media-has-caption -- a 30s song preview clip has no captions
            <audio controls src={song.preview_url} className="admin-song-detail-audio" />
          )}
        </div>
      </div>

      {error && <p className="form-error">{error}</p>}
      {notice && <p className="hint">{notice}</p>}

      <form onSubmit={onSave} className="admin-form">
        <label>
          Title
          <input
            type="text"
            value={form.title}
            onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))}
            required
          />
        </label>
        <label>
          Artist
          <input
            type="text"
            value={form.artist}
            onChange={(e) => setForm((f) => ({ ...f, artist: e.target.value }))}
            required
          />
        </label>
        <label>
          Genre
          <select
            value={form.genre}
            onChange={(e) => setForm((f) => ({ ...f, genre: e.target.value }))}
          >
            {GENRE_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        </label>
        <label>
          Popularity (0–100)
          <input
            type="number"
            min={0}
            max={100}
            value={form.popularity}
            onChange={(e) => setForm((f) => ({ ...f, popularity: e.target.value }))}
            required
          />
        </label>
        <label>
          Release year
          <input
            type="number"
            min={1900}
            max={new Date().getFullYear() + 1}
            value={form.releaseYear}
            onChange={(e) => setForm((f) => ({ ...f, releaseYear: e.target.value }))}
          />
        </label>
        <Button type="submit" disabled={busy}>
          Save changes
        </Button>
      </form>

      <section className="admin-moderation">
        <h2>Pool</h2>
        <Button
          variant={song.excluded ? "ghost" : "danger"}
          disabled={busy}
          onClick={() =>
            void run(
              () => updateSong(song.id, { excluded: !song.excluded }),
              song.excluded ? "Restored to the pool." : "Removed from the pool.",
            )
          }
        >
          {song.excluded ? "Restore to pool" : "Remove from pool"}
        </Button>
      </section>
    </div>
  );
}
