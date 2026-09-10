import { type FormEvent, useEffect, useRef, useState } from "react";
import { ArrowDown, ArrowUp, Trash2 } from "lucide-react";

import { firstValidationError } from "../../lib/errors";
import type { DubPackClip } from "../../lib/workshopTypes";
import { useWorkshopStore } from "../../stores/workshopStore";
import { Badge } from "../ui/Badge";
import { Button } from "../ui/Button";
import { DubClipReviewEditor } from "./DubClipReviewEditor";

type AddMode = "upload" | "youtube";

export function DubPackEditor({ datasetId, clips }: { datasetId: number; clips: DubPackClip[] }) {
  const addUpload = useWorkshopStore((s) => s.addDubClipUpload);
  const addYoutube = useWorkshopStore((s) => s.addDubClipYoutube);
  const reorder = useWorkshopStore((s) => s.reorderDubClips);
  const remove = useWorkshopStore((s) => s.removeDubClip);
  const fetchOne = useWorkshopStore((s) => s.fetchOne);
  const saving = useWorkshopStore((s) => s.saving);

  const [addMode, setAddMode] = useState<AddMode>("upload");
  const [title, setTitle] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [url, setUrl] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [editingClipId, setEditingClipId] = useState<number | null>(null);
  const fileInput = useRef<HTMLInputElement | null>(null);

  const anyProcessing = clips.some((c) => c.status === "processing");
  useEffect(() => {
    if (!anyProcessing) return;
    const id = setInterval(() => void fetchOne(datasetId), 4000);
    return () => clearInterval(id);
  }, [anyProcessing, datasetId, fetchOne]);

  function resetForm() {
    setTitle("");
    setFile(null);
    setUrl("");
    setError(null);
    if (fileInput.current) fileInput.current.value = "";
  }

  async function handleAdd(e: FormEvent) {
    e.preventDefault();
    setError(null);
    try {
      if (addMode === "youtube") {
        if (!url.trim()) {
          setError("Paste a video link.");
          return;
        }
        await addYoutube(datasetId, { title: title.trim(), url: url.trim() });
      } else {
        if (!file) {
          setError("Pick a video file.");
          return;
        }
        const fd = new FormData();
        fd.append("title", title.trim());
        fd.append("video", file);
        await addUpload(datasetId, fd);
      }
      resetForm();
    } catch (err) {
      setError(firstValidationError(err));
    }
  }

  function move(index: number, delta: number) {
    const target = index + delta;
    if (target < 0 || target >= clips.length) return;
    const next = [...clips];
    [next[index], next[target]] = [next[target], next[index]];
    void reorder(datasetId, next.map((c) => c.id));
  }

  return (
    <div className="dub-pack-editor">
      <p className="hint">
        Add short clips, author each one's characters and lines, then publish it. Once the pack has
        finished clips, submit it for review from the header above.
      </p>

      <form className="admin-form" onSubmit={(e) => void handleAdd(e)}>
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
          <label>
            Video (MP4 / WebM / MOV, up to 40 MB)
            <input
              ref={fileInput}
              type="file"
              accept="video/mp4,video/webm,video/quicktime"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
          </label>
        ) : (
          <label>
            YouTube link
            <input
              type="url"
              value={url}
              onChange={(e) => setUrl(e.target.value)}
              placeholder="https://www.youtube.com/watch?v=…"
            />
          </label>
        )}
        {error && <p className="form-error">{error}</p>}
        <Button type="submit" variant="grape" disabled={saving}>
          {saving ? "Working…" : "Add clip"}
        </Button>
      </form>

      {clips.length === 0 ? (
        <p className="hint">No clips yet.</p>
      ) : (
        <ul className="wk-list">
          {clips.map((clip, i) => {
            const tone =
              clip.status === "ready" ? "turquoise" : clip.status === "failed" ? "coral" : "sunflower";
            return (
              <li key={clip.id}>
                <span className="wk-q-reorder">
                  <button type="button" aria-label="Move up" disabled={i === 0} onClick={() => move(i, -1)}>
                    <ArrowUp size={14} strokeWidth={2.5} />
                  </button>
                  <button
                    type="button"
                    aria-label="Move down"
                    disabled={i === clips.length - 1}
                    onClick={() => move(i, 1)}
                  >
                    <ArrowDown size={14} strokeWidth={2.5} />
                  </button>
                </span>
                <span className="wk-info">
                  <span className="wk-name">{clip.title}</span>
                  <span className="wk-meta">
                    <Badge tone={tone}>{clip.status}</Badge>
                    <span className="hint">
                      {clip.character_count} characters · {clip.line_count} lines
                      {clip.status === "failed" && clip.processing_error ? ` — ${clip.processing_error}` : ""}
                    </span>
                  </span>
                </span>
                <span className="wk-actions">
                  {clip.status !== "processing" && (
                    <Button onClick={() => setEditingClipId(editingClipId === clip.id ? null : clip.id)}>
                      {editingClipId === clip.id ? "Close" : "Edit"}
                    </Button>
                  )}
                  <Button
                    variant="danger"
                    aria-label="Delete clip"
                    onClick={() => {
                      if (confirm(`Delete "${clip.title}"?`)) void remove(datasetId, clip.id);
                    }}
                  >
                    <Trash2 size={16} strokeWidth={2.25} />
                  </Button>
                </span>
                {editingClipId === clip.id && (
                  <div className="dub-pack-editor-inline">
                    <DubClipReviewEditor
                      clipId={clip.id}
                      endpointBase={`/api/datasets/${datasetId}/dub-clips`}
                      onPublished={() => {
                        setEditingClipId(null);
                        void fetchOne(datasetId);
                      }}
                    />
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
