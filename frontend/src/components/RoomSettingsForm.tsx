import { useEffect, useState, type FormEvent } from "react";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { GAME_MODES } from "../lib/gameModes";
import { SONG_GENRES } from "../lib/songGenres";
import type { GameMode, SongGenre } from "../lib/roomTypes";

interface RoomSettingsFormProps {
  code: string;
  songsPerTier: number;
  guessTimeoutSeconds: number;
  mode: GameMode;
  genre: SongGenre;
  yearFrom: number | null;
  yearTo: number | null;
  artistName: string | null;
}

const CURRENT_YEAR = new Date().getFullYear();
const DEFAULT_YEAR_FROM = 1970;
const DEFAULT_YEAR_TO = 1989;

interface SettingsPayload {
  songs_per_tier: number;
  guess_timeout_seconds: number;
  mode: GameMode;
  genre: SongGenre;
  year_from: number | null;
  year_to: number | null;
  artist_name: string | null;
}

/**
 * Host-only, lobby-only settings editor. Every control saves itself the
 * moment it changes (radios immediately, the number inputs on blur so a
 * PATCH isn't fired on every keystroke) - there's no separate "Save"
 * step. Saving broadcasts room.settings_updated to everyone (including
 * this tab, via gameStore's own listener), so this component doesn't need
 * to propagate the new values itself, just show a save confirmation/error.
 */
export function RoomSettingsForm({
  code,
  songsPerTier,
  guessTimeoutSeconds,
  mode,
  genre,
  yearFrom,
  yearTo,
  artistName,
}: RoomSettingsFormProps) {
  const [songs, setSongs] = useState(songsPerTier);
  const [timeout, setTimeoutSeconds] = useState(guessTimeoutSeconds);
  const [selectedMode, setSelectedMode] = useState<GameMode>(mode);
  const [selectedGenre, setSelectedGenre] = useState<SongGenre>(genre);
  const [selectedYearFrom, setSelectedYearFrom] = useState(yearFrom ?? DEFAULT_YEAR_FROM);
  const [selectedYearTo, setSelectedYearTo] = useState(yearTo ?? DEFAULT_YEAR_TO);
  const [selectedArtistName, setSelectedArtistName] = useState(artistName ?? "");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  // Keep the fields in sync if another host tab changes settings first
  // (the live broadcast updates the props this component receives).
  useEffect(() => setSongs(songsPerTier), [songsPerTier]);
  useEffect(() => setTimeoutSeconds(guessTimeoutSeconds), [guessTimeoutSeconds]);
  useEffect(() => setSelectedMode(mode), [mode]);
  useEffect(() => setSelectedGenre(genre), [genre]);
  useEffect(() => {
    if (yearFrom !== null) setSelectedYearFrom(yearFrom);
  }, [yearFrom]);
  useEffect(() => {
    if (yearTo !== null) setSelectedYearTo(yearTo);
  }, [yearTo]);
  useEffect(() => {
    if (artistName !== null) setSelectedArtistName(artistName);
  }, [artistName]);

  // Accepts explicit overrides rather than only reading state, since a
  // radio's onChange fires before the setState it triggers has re-rendered -
  // saving the new value directly avoids persisting a stale one.
  async function save(overrides: Partial<SettingsPayload> = {}) {
    const effectiveGenre = overrides.genre ?? selectedGenre;
    const payload: SettingsPayload = {
      songs_per_tier: overrides.songs_per_tier ?? songs,
      guess_timeout_seconds: overrides.guess_timeout_seconds ?? timeout,
      mode: overrides.mode ?? selectedMode,
      genre: effectiveGenre,
      year_from: effectiveGenre === "year" ? (overrides.year_from ?? selectedYearFrom) : null,
      year_to: effectiveGenre === "year" ? (overrides.year_to ?? selectedYearTo) : null,
      artist_name: effectiveGenre === "artist" ? (overrides.artist_name ?? selectedArtistName) : null,
    };

    setError(null);
    setSaved(false);
    setSaving(true);
    try {
      await api.patch(`/api/rooms/${code}`, payload);
      setSaved(true);
      setTimeout(() => setSaved(false), 2000);
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setSaving(false);
    }
  }

  // No submit handler needed (every control saves itself on change/blur -
  // see save() above) - this only exists so Enter in a field can't trigger
  // the browser's native form submission (a page navigation), and so
  // `form label` styling (see app.css) still applies without depending on
  // a <div> reproducing form-specific CSS.
  function suppressNativeSubmit(e: FormEvent) {
    e.preventDefault();
  }

  return (
    <form className="room-settings-form" onSubmit={suppressNativeSubmit}>
      <label>
        Songs per difficulty tier
        <input
          type="number"
          min={1}
          max={20}
          value={songs}
          onChange={(e) => setSongs(Number(e.target.value))}
          onBlur={() => void save({ songs_per_tier: songs })}
        />
      </label>
      <label>
        Seconds before a clip auto-extends
        <input
          type="number"
          min={3}
          max={60}
          value={timeout}
          onChange={(e) => setTimeoutSeconds(Number(e.target.value))}
          onBlur={() => void save({ guess_timeout_seconds: timeout })}
        />
      </label>
      <fieldset className="mode-picker">
        <legend>Mode</legend>
        {GAME_MODES.map((option) => (
          <label key={option.value} className="mode-option">
            <input
              type="radio"
              name="mode"
              value={option.value}
              checked={selectedMode === option.value}
              onChange={() => {
                setSelectedMode(option.value);
                void save({ mode: option.value });
              }}
            />
            <span>
              <strong>{option.label}</strong>
              <span className="hint">{option.description}</span>
            </span>
          </label>
        ))}
      </fieldset>
      <fieldset className="mode-picker">
        <legend>Genre</legend>
        {SONG_GENRES.map((option) => (
          <label key={option.value} className="mode-option">
            <input
              type="radio"
              name="genre"
              value={option.value}
              checked={selectedGenre === option.value}
              onChange={() => {
                setSelectedGenre(option.value);
                void save({ genre: option.value });
              }}
            />
            <span>
              <strong>{option.label}</strong>
              <span className="hint">{option.description}</span>
              {option.value === "year" && selectedGenre === "year" && (
                <span className="year-range-inputs">
                  <label>
                    From
                    <input
                      type="number"
                      min={1900}
                      max={CURRENT_YEAR}
                      value={selectedYearFrom}
                      onClick={(e) => e.stopPropagation()}
                      onChange={(e) => setSelectedYearFrom(Number(e.target.value))}
                      onBlur={() => void save({ year_from: selectedYearFrom })}
                    />
                  </label>
                  <label>
                    To
                    <input
                      type="number"
                      min={1900}
                      max={CURRENT_YEAR}
                      value={selectedYearTo}
                      onClick={(e) => e.stopPropagation()}
                      onChange={(e) => setSelectedYearTo(Number(e.target.value))}
                      onBlur={() => void save({ year_to: selectedYearTo })}
                    />
                  </label>
                </span>
              )}
              {option.value === "artist" && selectedGenre === "artist" && (
                <span className="year-range-inputs">
                  <label>
                    Artist
                    <input
                      type="text"
                      maxLength={100}
                      value={selectedArtistName}
                      onClick={(e) => e.stopPropagation()}
                      onChange={(e) => setSelectedArtistName(e.target.value)}
                      onBlur={() => void save({ artist_name: selectedArtistName })}
                    />
                  </label>
                </span>
              )}
            </span>
          </label>
        ))}
      </fieldset>
      {error && <p className="form-error">{error}</p>}
      <p className="hint save-status" aria-live="polite">
        {saving ? "Saving…" : saved ? "Saved" : " "}
      </p>
    </form>
  );
}
