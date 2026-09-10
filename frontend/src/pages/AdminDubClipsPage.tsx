import { type FormEvent, useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";

import { firstValidationError } from "../lib/errors";
import { useAdminDubClipsStore } from "../stores/adminDubClipsStore";
import { AdminNav } from "../components/AdminNav";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";

type AddMode = "upload" | "youtube";

export function AdminDubClipsPage() {
  const {
    clips,
    status,
    error,
    fetch,
    createClip,
    createClipYoutube,
    reingestClip,
    publishClip,
    unpublishClip,
    deleteClip,
  } = useAdminDubClipsStore();

  const [addMode, setAddMode] = useState<AddMode>("upload");
  const [title, setTitle] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [url, setUrl] = useState("");
  const [durationMs, setDurationMs] = useState<number | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const fileInput = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    void fetch();
  }, [fetch]);

  // Poll while a link download is running.
  const anyProcessing = clips.some((c) => c.status === "processing");
  useEffect(() => {
    if (!anyProcessing) return;
    const id = setInterval(() => void fetch(), 4000);
    return () => clearInterval(id);
  }, [anyProcessing, fetch]);

  function reset() {
    setTitle("");
    setFile(null);
    setUrl("");
    setDurationMs(null);
    setPreview(null);
    setFormError(null);
    if (fileInput.current) fileInput.current.value = "";
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);

    setBusy(true);
    try {
      if (addMode === "youtube") {
        if (!url.trim()) {
          setFormError("Paste a video link.");
          return;
        }
        await createClipYoutube({ title: title.trim(), url: url.trim() });
      } else {
        if (!file) {
          setFormError("Pick a video file.");
          return;
        }
        const fd = new FormData();
        fd.append("title", title.trim());
        fd.append("video", file);
        if (durationMs != null) fd.append("duration_ms", String(durationMs));
        await createClip(fd);
      }
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
        Add a short clip (upload a file or paste a YouTube link), author its characters and timed
        lines, then publish it — published public clips show up in the Dub Together lobby picker.
      </p>

      {error && <p className="form-error">{error}</p>}

      <form onSubmit={(e) => void onSubmit(e)} className="admin-form">
        <div className="admin-sync-bar">
          <Button
            type="button"
            variant={addMode === "upload" ? "turquoise" : "ghost"}
            onClick={() => setAddMode("upload")}
          >
            Upload a file
          </Button>
          <Button
            type="button"
            variant={addMode === "youtube" ? "turquoise" : "ghost"}
            onClick={() => setAddMode("youtube")}
          >
            From YouTube
          </Button>
        </div>

        <label>
          Title
          <input value={title} onChange={(e) => setTitle(e.target.value)} maxLength={120} required />
        </label>

        {addMode === "upload" ? (
          <>
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
          </>
        ) : (
          <label>
            YouTube link
            <input
              type="url"
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              placeholder="https://www.youtube.com/watch?v=…"
            />
            <span className="hint">
              The whole video is downloaded; trim it to a clip in the editor afterwards.
            </span>
          </label>
        )}

        {formError && <p className="form-error">{formError}</p>}
        <Button type="submit" variant="grape" disabled={busy}>
          {busy ? "Working…" : addMode === "youtube" ? "Fetch video" : "Add clip"}
        </Button>
      </form>

      {status !== "ready" && clips.length === 0 ? (
        <p className="hint">Loading…</p>
      ) : clips.length === 0 ? (
        <p className="hint">No clips yet.</p>
      ) : (
        <ul className="player-list">
          {clips.map((clip) => {
            const tone =
              clip.status === "ready"
                ? "turquoise"
                : clip.status === "failed"
                  ? "coral"
                  : "sunflower";
            return (
              <li key={clip.id}>
                <span className="friend-name">
                  {clip.title}
                  <span className="hint">
                    <Badge tone={tone}>{clip.status}</Badge>
                    {clip.is_public && <Badge tone="grape">public</Badge>}{" "}
                    {clip.character_count} characters · {clip.line_count} lines
                    {clip.duration_ms ? ` · ${Math.round(clip.duration_ms / 1000)}s` : ""}
                    {clip.status === "failed" && clip.processing_error ? ` — ${clip.processing_error}` : ""}
                  </span>
                </span>
                <span>
                  {clip.status === "processing" ? (
                    <span className="hint">Downloading…</span>
                  ) : (
                    <>
                      {clip.status === "failed" && clip.source_url && (
                        <Button variant="ghost" onClick={() => void reingestClip(clip.id)}>
                          Retry
                        </Button>
                      )}
                      <Link to={`/admin/dub-clips/${clip.id}`} className="btn btn-ghost">
                        Edit
                      </Link>
                      {clip.status === "ready" ? (
                        <Button variant="ghost" onClick={() => void unpublishClip(clip.id)}>
                          Unpublish
                        </Button>
                      ) : clip.status === "review" ? (
                        <Button variant="ghost" onClick={() => void publishClip(clip.id).catch(() => {})}>
                          Publish
                        </Button>
                      ) : null}
                    </>
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
            );
          })}
        </ul>
      )}
    </div>
  );
}
