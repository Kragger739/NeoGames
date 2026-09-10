import { useCallback, useEffect, useRef, useState } from "react";
import { ArrowDown, ArrowUp, Clock, Plus, Trash2 } from "lucide-react";

import { api } from "../../lib/api";
import { firstValidationError } from "../../lib/errors";
import { DUB_HUES, type DubClipDetail, type DubHue } from "../../lib/dubTypes";
import { Button } from "../ui/Button";

interface EditableCharacter {
  ref: string;
  display_name: string;
  color: DubHue;
}

interface EditableLine {
  character_ref: string;
  start_ms: number;
  end_ms: number;
  text: string;
}

interface DubClipReviewEditorProps {
  clipId: number;
  /** e.g. "/api/admin/dub-clips" (admin) or "/api/dub-clips" (host upload, Phase 3). */
  endpointBase: string;
  onPublished?: () => void;
}

function seedCharacters(clip: DubClipDetail): EditableCharacter[] {
  return clip.characters.map((c, i) => ({
    ref: String(c.id),
    display_name: c.display_name,
    color: (DUB_HUES as readonly string[]).includes(c.color ?? "")
      ? (c.color as DubHue)
      : DUB_HUES[i % DUB_HUES.length],
  }));
}

function seedLines(clip: DubClipDetail): EditableLine[] {
  return clip.lines.map((l) => ({
    character_ref: String(l.character_id),
    start_ms: l.start_ms,
    end_ms: l.end_ms,
    text: l.text ?? "",
  }));
}

export function DubClipReviewEditor({ clipId, endpointBase, onPublished }: DubClipReviewEditorProps) {
  const [clip, setClip] = useState<DubClipDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [characters, setCharacters] = useState<EditableCharacter[]>([]);
  const [lines, setLines] = useState<EditableLine[]>([]);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState(false);
  const tmpCounter = useRef(0);
  const videoRef = useRef<HTMLVideoElement>(null);

  const hydrate = useCallback((detail: DubClipDetail) => {
    setClip(detail);
    setCharacters(seedCharacters(detail));
    setLines(seedLines(detail));
    setDirty(false);
  }, []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    api
      .get<DubClipDetail>(`${endpointBase}/${clipId}`)
      .then((r) => {
        if (!cancelled) hydrate(r.data);
      })
      .catch((err) => {
        if (!cancelled) setError(firstValidationError(err));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [clipId, endpointBase, hydrate]);

  const readOnly = clip?.status === "ready";

  function mutateCharacters(next: EditableCharacter[]) {
    setCharacters(next);
    setDirty(true);
  }
  function mutateLines(next: EditableLine[]) {
    setLines(next);
    setDirty(true);
  }

  function addCharacter() {
    const n = characters.length;
    mutateCharacters([
      ...characters,
      { ref: `tmp-${tmpCounter.current++}`, display_name: `Character ${n + 1}`, color: DUB_HUES[n % DUB_HUES.length] },
    ]);
  }

  function removeCharacter(idx: number) {
    const removed = characters[idx];
    const orphaned = lines.filter((l) => l.character_ref === removed.ref).length;
    if (orphaned > 0 && !window.confirm(`Remove this character and its ${orphaned} line(s)?`)) return;
    mutateCharacters(characters.filter((_, i) => i !== idx));
    setLines(lines.filter((l) => l.character_ref !== removed.ref));
    setDirty(true);
  }

  function addLine() {
    const last = lines[lines.length - 1];
    const start = last ? last.end_ms + 100 : 0;
    mutateLines([
      ...lines,
      { character_ref: characters[0]?.ref ?? "", start_ms: start, end_ms: start + 1500, text: "" },
    ]);
  }

  function updateLine(idx: number, patch: Partial<EditableLine>) {
    mutateLines(lines.map((l, i) => (i === idx ? { ...l, ...patch } : l)));
  }

  function moveLine(idx: number, delta: number) {
    const target = idx + delta;
    if (target < 0 || target >= lines.length) return;
    const next = [...lines];
    [next[idx], next[target]] = [next[target], next[idx]];
    mutateLines(next);
  }

  function grabTime(idx: number, field: "start_ms" | "end_ms") {
    const t = Math.round((videoRef.current?.currentTime ?? 0) * 1000);
    updateLine(idx, { [field]: t });
  }

  async function save() {
    setError(null);
    setBusy(true);
    try {
      const { data } = await api.put<DubClipDetail>(`${endpointBase}/${clipId}/script`, {
        characters: characters.map((c) => ({ ref: c.ref, display_name: c.display_name.trim(), color: c.color })),
        lines: lines.map((l) => ({
          character_ref: l.character_ref,
          start_ms: l.start_ms,
          end_ms: l.end_ms,
          text: l.text.trim() || null,
        })),
      });
      hydrate(data);
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setBusy(false);
    }
  }

  async function publish() {
    setError(null);
    setBusy(true);
    try {
      await api.post(`${endpointBase}/${clipId}/publish`);
      onPublished?.();
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setBusy(false);
    }
  }

  async function unpublish() {
    setError(null);
    setBusy(true);
    try {
      await api.post(`${endpointBase}/${clipId}/unpublish`);
      const { data } = await api.get<DubClipDetail>(`${endpointBase}/${clipId}`);
      hydrate(data);
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <p className="hint">Loading clip…</p>;
  if (!clip) return <p className="form-error">{error ?? "Clip not found."}</p>;

  const publishBlockers: string[] = [];
  if (!clip.video_url) publishBlockers.push("no video");
  if (characters.length === 0) publishBlockers.push("no characters");
  if (lines.length === 0) publishBlockers.push("no lines");
  if (lines.some((l) => l.end_ms <= l.start_ms)) publishBlockers.push("a line ends before it starts");
  if (lines.some((l) => !characters.some((c) => c.ref === l.character_ref))) publishBlockers.push("a line has no character");
  if (dirty) publishBlockers.push("unsaved changes");

  const nameFor = (ref: string) => characters.find((c) => c.ref === ref)?.display_name ?? "—";

  return (
    <div className="dub-editor">
      <div className="dub-editor-head">
        <h2>{clip.title}</h2>
        <span className={clip.status === "ready" ? "dub-ready-badge is-yes" : "dub-ready-badge"}>
          {clip.status === "ready" ? "Published" : "Draft"}
        </span>
      </div>

      {clip.video_url ? (
        <video ref={videoRef} className="dub-editor-video" src={clip.video_url} controls playsInline />
      ) : (
        <p className="form-error">This clip has no video file.</p>
      )}

      {error && <p className="form-error">{error}</p>}

      <section className="dub-editor-section">
        <div className="dub-editor-section-head">
          <h3>Characters</h3>
          {!readOnly && (
            <Button variant="ghost" onClick={addCharacter}>
              <Plus size={15} strokeWidth={2.5} /> Add character
            </Button>
          )}
        </div>
        <ul className="dub-editor-rows">
          {characters.map((c, i) => (
            <li key={c.ref} className="dub-editor-row" data-hue={c.color}>
              <input
                value={c.display_name}
                disabled={readOnly}
                maxLength={80}
                onChange={(e) => mutateCharacters(characters.map((x, xi) => (xi === i ? { ...x, display_name: e.target.value } : x)))}
              />
              <select
                value={c.color}
                disabled={readOnly}
                onChange={(e) => mutateCharacters(characters.map((x, xi) => (xi === i ? { ...x, color: e.target.value as DubHue } : x)))}
              >
                {DUB_HUES.map((h) => (
                  <option key={h} value={h}>{h}</option>
                ))}
              </select>
              {!readOnly && (
                <Button variant="danger" aria-label="Remove character" onClick={() => removeCharacter(i)}>
                  <Trash2 size={15} strokeWidth={2.25} />
                </Button>
              )}
            </li>
          ))}
          {characters.length === 0 && <li className="hint">No characters yet.</li>}
        </ul>
      </section>

      <section className="dub-editor-section">
        <div className="dub-editor-section-head">
          <h3>Lines</h3>
          {!readOnly && characters.length > 0 && (
            <Button variant="ghost" onClick={addLine}>
              <Plus size={15} strokeWidth={2.5} /> Add line
            </Button>
          )}
        </div>
        <ol className="dub-editor-lines">
          {lines.map((l, i) => (
            <li key={i} className="dub-editor-line">
              <div className="dub-editor-line-reorder">
                <button type="button" aria-label="Move up" disabled={readOnly || i === 0} onClick={() => moveLine(i, -1)}>
                  <ArrowUp size={14} strokeWidth={2.5} />
                </button>
                <button type="button" aria-label="Move down" disabled={readOnly || i === lines.length - 1} onClick={() => moveLine(i, 1)}>
                  <ArrowDown size={14} strokeWidth={2.5} />
                </button>
              </div>
              <select
                value={l.character_ref}
                disabled={readOnly}
                onChange={(e) => updateLine(i, { character_ref: e.target.value })}
              >
                <option value="">— character —</option>
                {characters.map((c) => (
                  <option key={c.ref} value={c.ref}>{c.display_name}</option>
                ))}
              </select>
              <label className="dub-editor-ms">
                start
                <input
                  type="number"
                  min={0}
                  value={l.start_ms}
                  disabled={readOnly}
                  onChange={(e) => updateLine(i, { start_ms: Number(e.target.value) })}
                />
                {!readOnly && (
                  <button type="button" aria-label="Use current video time" onClick={() => grabTime(i, "start_ms")}>
                    <Clock size={13} strokeWidth={2.5} />
                  </button>
                )}
              </label>
              <label className="dub-editor-ms">
                end
                <input
                  type="number"
                  min={1}
                  value={l.end_ms}
                  disabled={readOnly}
                  onChange={(e) => updateLine(i, { end_ms: Number(e.target.value) })}
                />
                {!readOnly && (
                  <button type="button" aria-label="Use current video time" onClick={() => grabTime(i, "end_ms")}>
                    <Clock size={13} strokeWidth={2.5} />
                  </button>
                )}
              </label>
              <input
                className="dub-editor-line-text"
                placeholder={`${nameFor(l.character_ref)} says…`}
                value={l.text}
                disabled={readOnly}
                maxLength={500}
                onChange={(e) => updateLine(i, { text: e.target.value })}
              />
              {!readOnly && (
                <Button variant="danger" aria-label="Remove line" onClick={() => mutateLines(lines.filter((_, li) => li !== i))}>
                  <Trash2 size={15} strokeWidth={2.25} />
                </Button>
              )}
            </li>
          ))}
          {lines.length === 0 && <li className="hint">No lines yet.</li>}
        </ol>
      </section>

      <div className="dub-editor-actions">
        {readOnly ? (
          <Button variant="ghost" disabled={busy} onClick={() => void unpublish()}>
            Unpublish to edit
          </Button>
        ) : (
          <>
            <Button variant="turquoise" disabled={busy} onClick={() => void save()}>
              {busy ? "Saving…" : dirty ? "Save script" : "Saved"}
            </Button>
            <Button variant="grape" disabled={busy || publishBlockers.length > 0} onClick={() => void publish()}>
              Publish
            </Button>
          </>
        )}
      </div>
      {!readOnly && publishBlockers.length > 0 && (
        <p className="hint">Can't publish yet: {publishBlockers.join(", ")}.</p>
      )}
    </div>
  );
}
