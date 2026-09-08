import { useEffect, useRef, useState, type FormEvent } from "react";

import {
  useAdminIconicArtistsStore,
  type AdminIconicArtist,
} from "../stores/adminIconicArtistsStore";
import { AdminNav } from "../components/AdminNav";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";

const EMPTY = { id: 0, name: "", enabled: true, sortOrder: 0 };

export function AdminIconicArtistsPage() {
  const { artists, status, error, fetch, createArtist, updateArtist, deleteArtist } =
    useAdminIconicArtistsStore();

  const [form, setForm] = useState(EMPTY);
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const fileInput = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    void fetch();
  }, [fetch]);

  function reset() {
    setForm(EMPTY);
    setFile(null);
    setPreview(null);
    if (fileInput.current) fileInput.current.value = "";
  }

  function edit(artist: AdminIconicArtist) {
    setForm({ id: artist.id, name: artist.name, enabled: artist.enabled, sortOrder: artist.sort_order });
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
        Curated acts for the Songle landing-page carousel. <strong>Name</strong> must match the
        song pool's artist spelling exactly — <span className="hint">pool</span> below is how
        many playable tracks match.
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
          Sort order
          <input
            type="number"
            min={0}
            value={form.sortOrder}
            onChange={(e) => setForm((f) => ({ ...f, sortOrder: Number(e.target.value) }))}
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
                  · pool {artist.pool_size} · order {artist.sort_order}
                </span>
                {!artist.enabled && <Badge tone="coral">Hidden</Badge>}
              </span>
              <span>
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
