import { type FormEvent, useEffect, useState } from "react";

import { api } from "../lib/api";

interface SongSuggestion {
  provider_track_id: string;
  title: string;
  artist: string;
  album_art_url: string | null;
}

interface GuessAutocompleteProps {
  disabled: boolean;
  submitting: boolean;
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
  isSolo,
  onSubmit,
}: GuessAutocompleteProps) {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<SongSuggestion[]>([]);
  const [open, setOpen] = useState(false);
  const [status, setStatus] = useState<"idle" | "loading" | "done" | "error">("idle");

  useEffect(() => {
    if (query.trim().length < MIN_QUERY_LENGTH) {
      setResults([]);
      setStatus("idle");
      return;
    }

    // Cancels any still-in-flight search from a previous keystroke, so a
    // burst of typing doesn't queue up multiple slow round-trips behind
    // each other - only the latest query's request is ever outstanding.
    const controller = new AbortController();

    const handle = setTimeout(() => {
      setStatus("loading");
      api
        .get<{ results: SongSuggestion[] }>("/api/songs/search", {
          params: { q: query },
          signal: controller.signal,
        })
        .then((response) => {
          setResults(response.data.results ?? []);
          setStatus("done");
        })
        .catch((err) => {
          if (err.code === "ERR_CANCELED") return;
          // Don't fail silently - an empty dropdown is indistinguishable
          // from "no matches" otherwise.
          console.warn("song search failed", err);
          setResults([]);
          setStatus("error");
        });
    }, DEBOUNCE_MS);

    return () => {
      clearTimeout(handle);
      controller.abort();
    };
  }, [query]);

  function selectSuggestion(suggestion: SongSuggestion) {
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
      setOpen(false);
      onSubmit(SKIP_GUESS_TEXT);
      return;
    }
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
          onBlur={() => setTimeout(() => setOpen(false), 150)}
          placeholder="Song title or artist…"
          autoFocus
          disabled={disabled}
        />
        <button type="submit" disabled={disabled || submitting}>
          {submitting ? "Submitting…" : showSkip ? "Skip" : "Guess"}
        </button>
      </form>

      {open && results.length === 0 && query.trim().length >= MIN_QUERY_LENGTH && status !== "idle" && (
        <ul className="guess-suggestions">
          <li className="hint">
            {status === "loading"
              ? "Searching…"
              : status === "error"
                ? "Search unavailable — try again"
                : "No matching song in the pool"}
          </li>
        </ul>
      )}

      {open && results.length > 0 && (
        <ul className="guess-suggestions">
          {results.map((suggestion) => (
            <li key={suggestion.provider_track_id}>
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
    </div>
  );
}
