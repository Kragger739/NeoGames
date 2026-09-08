import { useEffect, useRef, useState, type FormEvent } from "react";

import {
  ICONIC_FETCH_IN_FLIGHT,
  useAdminIconicArtistsStore,
  type AdminIconicArtist,
} from "../stores/adminIconicArtistsStore";
import { AdminNav } from "../components/AdminNav";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";

const EMPTY = { id: 0, name: "", enabled: true, sortOrder: 0, price: 0, appleArtist: "" };

function fetchLine(a: AdminIconicArtist): string {
  switch (a.fetch_status) {
    case "pending":
    case "discovering":
      return "Finding songs…";
    case "seeding":
      return `Fetching ${a.fetch_resolved}/${a.fetch_total || "…"}…`;
    case "done":
      return `✓ ${a.top20_count}/20 hits ready · ${a.fetched_playable} playable`;
    case "failed":
      return "Fetch failed";
    default:
      return "";
  }
}

export function AdminIconicArtistsPage() {
  const { artists, status, error, fetch, createArtist, updateArtist, deleteArtist, refetch } =
    useAdminIconicArtistsStore();

  const [form, setForm] = useState(EMPTY);
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const fileInput = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    void fetch();
  }, [fetch]);

  // While any artist's catalogue fetch is running, poll the list so the
  // progress line updates without a manual reload.
  const anyInFlight = artists.some((a) => ICONIC_FETCH_IN_FLIGHT.includes(a.fetch_status));
  useEffect(() => {
    if (!anyInFlight) return;
    const id = setInterval(() => void fetch(), 4000);
    return () => clearInterval(id);
  }, [anyInFlight, fetch]);

  function reset() {
    setForm(EMPTY);
    setFile(null);
    setPreview(null);
    if (fileInput.current) fileInput.current.value = "";
  }

  function edit(artist: AdminIconicArtist) {
    setForm({
      id: artist.id,
      name: artist.name,
      enabled: artist.enabled,
      sortOrder: artist.sort_order,
      price: artist.price,
      appleArtist: artist.apple_artist_id ? String(artist.apple_artist_id) : "",
    });
    setFile(null);
    setPreview(artist.image_url);
    if (fileInput.current) fileInput.current.value = "";
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    const fd = new FormData();
    fd.append("name", form.name.trim());
    fd.append("enabled", form.enabled ? "1" : "0");
    fd.append("sort_order", String(form.sortOrder));
    fd.append("price", String(form.price));
    fd.append("apple_artist", form.appleArtist.trim());
    if (file) fd.append("image", file);

    setBusy(true);
    try {
      if (form.id) await updateArtist(form.id, fd);
      else await createArtist(fd);
      reset();
    } catch {
      // store surfaces the error
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="admin-page">
      <AdminNav />
      <h1>Iconic Artists</h1>
      <p className="hint">
        Curated acts for the Songle landing-page carousel. <strong>Name</strong> must match how
        iTunes spells the artist (it&rsquo;s what in-game rounds match against). Paste the
        artist&rsquo;s <strong>Apple Music link</strong> to pin the catalogue fetch to the exact
        right artist. Adding one auto-fetches ~100 of their songs; games play the top 20.
      </p>

      {error && <p className="form-error">{error}</p>}

      <form onSubmit={onSubmit} className="admin-form">
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
          Apple Music artist link (or numeric ID)
          <input
            type="text"
            placeholder="https://music.apple.com/us/artist/…/159260351"
            value={form.appleArtist}
            onChange={(e) => setForm((f) => ({ ...f, appleArtist: e.target.value }))}
          />
        </label>
        <label>
          Sort order
          <input
            type="number"
            min={0}
            value={form.sortOrder}
            onChange={(e) => setForm((f) => ({ ...f, sortOrder: Number(e.target.value) }))}
          />
        </label>
        <label>
          Price (NeoCoins, 0 = free)
          <input
            type="number"
            min={0}
            value={form.price}
            onChange={(e) => setForm((f) => ({ ...f, price: Math.max(0, Number(e.target.value)) }))}
          />
        </label>
        <label className="admin-check">
          <input
            type="checkbox"
            checked={form.enabled}
            onChange={(e) => setForm((f) => ({ ...f, enabled: e.target.checked }))}
          />
          Enabled (shown in the carousel)
        </label>
        <label>
          Photo (PNG / WebP / JPG)
          <input
            ref={fileInput}
            type="file"
            accept="image/png,image/webp,image/jpeg"
            onChange={(e) => {
              const f = e.target.files?.[0] ?? null;
              setFile(f);
              setPreview(f ? URL.createObjectURL(f) : preview);
            }}
          />
        </label>
        {preview && (
          <img src={preview} alt="" style={{ width: 96, height: 96, objectFit: "cover", borderRadius: 12 }} />
        )}
        <div className="admin-sync-bar">
          <Button type="submit" disabled={busy}>
            {form.id ? "Save changes" : "Add artist"}
          </Button>
          {form.id !== 0 && (
            <Button type="button" variant="ghost" onClick={reset}>
              Cancel
            </Button>
          )}
        </div>
      </form>

      {status !== "ready" && artists.length === 0 ? (
        <p className="hint">Loading…</p>
      ) : artists.length === 0 ? (
        <p className="hint">No iconic artists yet.</p>
      ) : (
        <ul className="player-list">
          {artists.map((artist) => (
            <li key={artist.id}>
              <span className="friend-name">
                {artist.image_url ? (
                  <img
                    src={artist.image_url}
                    alt=""
                    style={{ width: 36, height: 36, objectFit: "cover", borderRadius: 8 }}
                  />
                ) : (
                  <span
                    className="art-placeholder"
                    style={{ width: 36, height: 36, borderRadius: 8, display: "inline-block" }}
                    aria-hidden="true"
                  />
                )}
                {artist.name}
                <span className="hint">
                  {" "}
                  · {fetchLine(artist)} · order {artist.sort_order} ·{" "}
                  {artist.price > 0 ? `◈ ${artist.price}` : "free"}
                  {artist.apple_artist_id ? " · 🔗 pinned" : ""}
                </span>
                {!artist.enabled && <Badge tone="coral">Hidden</Badge>}
                {artist.free_this_week && <Badge tone="turquoise">Free this week</Badge>}
                {artist.fetch_status === "failed" && artist.fetch_error && (
                  <span className="form-error">{artist.fetch_error}</span>
                )}
              </span>
              <span>
                <Button variant="ghost" onClick={() => void refetch(artist.id)}>
                  Re-fetch
                </Button>
                <Button variant="ghost" onClick={() => edit(artist)}>
                  Edit
                </Button>
                <Button
                  variant="ghost"
                  onClick={() => {
                    if (window.confirm(`Delete "${artist.name}"?`)) void deleteArtist(artist.id);
                  }}
                >
                  Delete
                </Button>
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
