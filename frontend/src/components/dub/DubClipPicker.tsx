import { useEffect, useState } from "react";

import { api } from "../../lib/api";
import type { DubClipListItem } from "../../lib/dubTypes";
import { Button } from "../ui/Button";

interface DubClipPickerProps {
  selectedClipId: number | null;
  onPick: (clipId: number) => void | Promise<void>;
  disabled?: boolean;
}

/**
 * Lobby / "play another" clip chooser. PHASE 1: a read-only list of ready
 * library clips from GET /api/dub-clips. Phase 2 adds the "Upload a clip"
 * entry point + the review-and-fix editor.
 */
export function DubClipPicker({ selectedClipId, onPick, disabled }: DubClipPickerProps) {
  const [clips, setClips] = useState<DubClipListItem[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    api
      .get<{ clips: DubClipListItem[] }>("/api/dub-clips")
      .then((r) => {
        if (!cancelled) setClips(r.data.clips);
      })
      .catch(() => {
        if (!cancelled) setClips([]);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  if (loading) return <p className="hint">Loading clips…</p>;
  if (clips.length === 0) return <p className="hint">No clips are ready yet. An admin needs to add one.</p>;

  return (
    <ul className="dub-clip-list">
      {clips.map((clip) => (
        <li key={clip.id} className={clip.id === selectedClipId ? "dub-clip-card is-selected" : "dub-clip-card"}>
          <div className="dub-clip-card-body">
            <h4>{clip.title}</h4>
            <p className="hint">
              {clip.character_count} characters · {clip.line_count} lines
              {clip.duration_ms ? ` · ${Math.round(clip.duration_ms / 1000)}s` : ""}
            </p>
          </div>
          <Button
            variant={clip.id === selectedClipId ? "turquoise" : "ghost"}
            disabled={disabled}
            onClick={() => void onPick(clip.id)}
          >
            {clip.id === selectedClipId ? "Selected" : "Choose"}
          </Button>
        </li>
      ))}
    </ul>
  );
}
