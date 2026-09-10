import { type FormEvent, useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";

import { firstValidationError } from "../lib/errors";
import { useAdminDubClipsStore } from "../stores/adminDubClipsStore";
import { AdminNav } from "../components/AdminNav";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";

export function AdminDubClipsPage() {
  const { clips, status, error, fetch, createClip, publishClip, unpublishClip, deleteClip } =
    useAdminDubClipsStore();

  const [title, setTitle] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [durationMs, setDurationMs] = useState<number | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const fileInput = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    void fetch();
  }, [fetch]);

  function reset() {
    setTitle("");
    setFile(null);
    setDurationMs(null);
    setPreview(null);
    setFormError(null);
    if (fileInput.current) fileInput.current.value = "";
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (!file) {
      setFormError("Pick a video file.");
      return;
    }
    const fd = new FormData();
    fd.append("title", title.trim());
    fd.append("video", file);
    if (durationMs != null) fd.append("duration_ms", String(durationMs));

    setBusy(true);
    try {
      await createClip(fd);
      reset();
    } catch (err) {
      setFormError(firstValidationError(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="admin-page">
      <AdminNav />
      <h1>Dub clips</h1>
      <p className="hint">
        Upload a short muted clip, author its characters and timed lines, then publish it — published
        public clips show up in the Dub Together lobby picker.
      </p>

      {error && <p className="form-error">{error}</p>}

      <form onSubmit={(e) => void onSubmit(e)} className="admin-form">
        <label>
          Title
          <input value={title} onChange={(e) => setTitle(e.target.value)} maxLength={120} required />
        </label>
        <label>
          Video (MP4 / WebM / MOV, up to 40 MB)
          <input
            ref={fileInput}
            type="file"
            accept="video/mp4,video/webm,video/quicktime"
            onChange={(e) => {
              const f = e.target.files?.[0] ?? null;
              setFile(f);
              setDurationMs(null);
              setPreview(f ? URL.createObjectURL(f) : null);
            }}
          />
        </label>
        {preview && (
          <video
            src={preview}
            controls
            style={{ maxWidth: 320, borderRadius: 12 }}
            onLoadedMetadata={(e) => setDurationMs(Math.round(e.currentTarget.duration * 1000) || null)}
          />
        )}
        {formError && <p className="form-error">{formError}</p>}
        <Button type="submit" variant="grape" disabled={busy}>
          {busy ? "Uploading…" : "Add clip"}
        </Button>
      </form>

      {status !== "ready" && clips.length === 0 ? (
        <p className="hint">Loading…</p>
      ) : clips.length === 0 ? (
        <p className="hint">No clips yet.</p>
      ) : (
        <ul className="player-list">
          {clips.map((clip) => (
            <li key={clip.id}>
              <span className="friend-name">
                {clip.title}
                <span className="hint">
                  <Badge tone={clip.status === "ready" ? "turquoise" : "sunflower"}>{clip.status}</Badge>
                  {clip.is_public && <Badge tone="grape">public</Badge>}{" "}
                  {clip.character_count} characters · {clip.line_count} lines
                  {clip.duration_ms ? ` · ${Math.round(clip.duration_ms / 1000)}s` : ""}
                </span>
              </span>
              <span>
                <Link to={`/admin/dub-clips/${clip.id}`} className="btn btn-ghost">
                  Edit
                </Link>
                {clip.status === "ready" ? (
                  <Button variant="ghost" onClick={() => void unpublishClip(clip.id)}>
                    Unpublish
                  </Button>
                ) : (
                  <Button variant="ghost" onClick={() => void publishClip(clip.id).catch(() => {})}>
                    Publish
                  </Button>
                )}
                <Button
                  variant="ghost"
                  onClick={() => {
                    if (window.confirm(`Delete "${clip.title}"?`)) void deleteClip(clip.id);
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
