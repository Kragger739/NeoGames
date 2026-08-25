import { type FormEvent, useEffect, useRef, useState } from "react";

import { api } from "../lib/api";

interface SongSuggestion {
  deezer_track_id: string;
  title: string;
  artist: string;
  album_art_url: string | null;
  preview_url: string;
}

interface GuessAutocompleteProps {
  disabled: boolean;
  submitting: boolean;
  volume: number;
  isSolo: boolean;
  onSubmit: (guess: string) => void;
}

const DEBOUNCE_MS = 350;
const MIN_QUERY_LENGTH = 2;

// Solo's "Skip" button reuses the normal wrong-guess path (this text will
// never match a real song's title/artist) rather than needing its own
// endpoint - a wrong guess in Solo already escalates the stage immediately
// (see RoundService::escalateSoloStage()), which is exactly what a skip
// should do. Classic/Battle Royale have no skip - other players are still
// playing, and those modes already progress on a timer regardless.
const SKIP_GUESS_TEXT = "[skip]";

export function GuessAutocomplete({
  disabled,
  submitting,
  volume,
  isSolo,
  onSubmit,
}: GuessAutocompleteProps) {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<SongSuggestion[]>([]);
  const [open, setOpen] = useState(false);
  const [searching, setSearching] = useState(false);
  // Which suggestion (if any) currently has its preview playing - null means
  // none. One shared <audio> element handles playback for every suggestion
  // row, so starting a new preview naturally stops whatever was playing.
  const [previewingId, setPreviewingId] = useState<string | null>(null);
  const audioRef = useRef<HTMLAudioElement>(null);

  useEffect(() => {
    if (query.trim().length < MIN_QUERY_LENGTH) {
      setResults([]);
      setSearching(false);
      return;
    }

    // Cancels any still-in-flight search from a previous keystroke, so a
    // burst of typing doesn't queue up multiple slow round-trips behind
    // each other - only the latest query's request is ever outstanding.
    const controller = new AbortController();

    const handle = setTimeout(() => {
      setSearching(true);
      api
        .get<{ results: SongSuggestion[] }>("/api/songs/search", {
          params: { q: query },
          signal: controller.signal,
        })
        .then((response) => setResults(response.data.results))
        .catch((err) => {
          if (err.code !== "ERR_CANCELED") setResults([]);
        })
        .finally(() => setSearching(false));
    }, DEBOUNCE_MS);

    return () => {
      clearTimeout(handle);
      controller.abort();
    };
  }, [query]);

  function stopPreview() {
    audioRef.current?.pause();
    setPreviewingId(null);
  }

  function togglePreview(suggestion: SongSuggestion) {
    const audio = audioRef.current;
    if (!audio) return;

    if (previewingId === suggestion.deezer_track_id) {
      stopPreview();
      return;
    }

    audio.src = suggestion.preview_url;
    audio.volume = volume;
    void audio.play();
    setPreviewingId(suggestion.deezer_track_id);
  }

  function selectSuggestion(suggestion: SongSuggestion) {
    stopPreview();
    setOpen(false);
    setQuery("");
    setResults([]);
    onSubmit(suggestion.title);
  }

  const isEmpty = !query.trim();
  const showSkip = isSolo && isEmpty;

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (isEmpty) {
      if (!isSolo) return;
      stopPreview();
      setOpen(false);
      onSubmit(SKIP_GUESS_TEXT);
      return;
    }
    stopPreview();
    setOpen(false);
    onSubmit(query);
    setQuery("");
    setResults([]);
  }

  return (
    <div className="guess-autocomplete">
      <form onSubmit={handleSubmit}>
        <input
          value={query}
          onChange={(e) => {
            setQuery(e.target.value);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          onBlur={() => setTimeout(() => {
            setOpen(false);
            stopPreview();
          }, 150)}
          placeholder="Song title or artist…"
          autoFocus
          disabled={disabled}
        />
        <button type="submit" disabled={disabled || submitting}>
          {submitting ? "Submitting…" : showSkip ? "Skip" : "Guess"}
        </button>
      </form>

      {open && results.length > 0 && (
        <ul className="guess-suggestions">
          {results.map((suggestion) => (
            <li key={suggestion.deezer_track_id}>
              {suggestion.album_art_url ? (
                <img
                  className="suggestion-art"
                  src={suggestion.album_art_url}
                  alt=""
                  width={32}
                  height={32}
                />
              ) : (
                <span className="suggestion-art art-placeholder" aria-hidden="true" />
              )}
              <button
                type="button"
                className="suggestion-preview"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => togglePreview(suggestion)}
                aria-label={
                  previewingId === suggestion.deezer_track_id
                    ? "Stop preview"
                    : "Play preview"
                }
              >
                {previewingId === suggestion.deezer_track_id ? "⏸" : "▶"}
              </button>
              <button
                type="button"
                className="suggestion-label"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => selectSuggestion(suggestion)}
              >
                {suggestion.title} — {suggestion.artist}
              </button>
            </li>
          ))}
        </ul>
      )}
      <audio ref={audioRef} onEnded={() => setPreviewingId(null)} />
    </div>
  );
}
