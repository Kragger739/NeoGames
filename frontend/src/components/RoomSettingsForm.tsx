import { useEffect, useState, type FormEvent } from "react";
import { Lock } from "lucide-react";

import { api } from "../lib/api";
import { DIFFICULTY_TIERS } from "../lib/difficultyTiers";
import { firstValidationError } from "../lib/errors";
import { GAME_MODES } from "../lib/gameModes";
import { PLAYER_MODES } from "../lib/playerModes";
import { MULTI_ARTIST_MAX, SONG_GENRES } from "../lib/songGenres";
import type { GameMode, PlayerMode, SongGenre } from "../lib/roomTypes";
import type { DatasetsIndex, DatasetSummary } from "../lib/workshopTypes";
import { useUnlockStore } from "../stores/unlockStore";

interface ArtistSuggestion {
  provider_artist_id: string;
  name: string;
  picture_url: string | null;
  follower_count: number;
}

interface RoomSettingsFormProps {
  code: string;
  songsPerTier: number;
  enabledTiers: string[];
  guessTimeoutSeconds: number;
  mode: GameMode;
  playerMode: PlayerMode;
  genre: SongGenre;
  yearFrom: number | null;
  yearTo: number | null;
  artistName: string | null;
  artistNames: string[] | null;
  datasetId: number | null;
  datasetName: string | null;
  hostLevel: number | null;
}

const CURRENT_YEAR = new Date().getFullYear();
const DEFAULT_YEAR_FROM = 1970;
const DEFAULT_YEAR_TO = 1989;

interface SettingsPayload {
  songs_per_tier: number;
  enabled_tiers: string[];
  guess_timeout_seconds: number;
  mode: GameMode;
  player_mode: PlayerMode;
  genre: SongGenre;
  year_from: number | null;
  year_to: number | null;
  artist_name: string | null;
  artist_names: string[] | null;
  dataset_id: number | null;
}

const DATASET_MIN_ROUNDS = 1;
const DATASET_MAX_ROUNDS = 30;

/**
 * Debounced Spotify artist-name search, shared by the single-artist (Artist
 * genre) and multi-select (Multi-artist genre) pickers - each call gets its
 * own independent query/enabled state, so the two pickers never interfere.
 */
function useArtistSearch(query: string, enabled: boolean): ArtistSuggestion[] {
  const [results, setResults] = useState<ArtistSuggestion[]>([]);

  useEffect(() => {
    if (!enabled || query.trim().length < 2) {
      setResults([]);
      return;
    }

    const controller = new AbortController();
    const handle = setTimeout(() => {
      api
        .get<{ results: ArtistSuggestion[] }>("/api/artists/search", {
          params: { q: query },
          signal: controller.signal,
        })
        .then((response) => setResults(response.data.results))
        .catch((err) => {
          if (err.code !== "ERR_CANCELED") setResults([]);
        });
    }, 350);

    return () => {
      clearTimeout(handle);
      controller.abort();
    };
  }, [query, enabled]);

  return results;
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
  enabledTiers,
  guessTimeoutSeconds,
  mode,
  playerMode,
  genre,
  yearFrom,
  yearTo,
  artistName,
  artistNames,
  datasetId,
  datasetName,
  hostLevel,
}: RoomSettingsFormProps) {
  const fetchUnlocks = useUnlockStore((state) => state.fetch);
  const requiredLevel = useUnlockStore((state) => state.requiredLevel);

  useEffect(() => {
    void fetchUnlocks();
  }, [fetchUnlocks]);

  const [songs, setSongs] = useState(songsPerTier);
  const [selectedTiers, setSelectedTiers] = useState<string[]>(enabledTiers);
  const [timeout, setTimeoutSeconds] = useState(guessTimeoutSeconds);
  const [selectedMode, setSelectedMode] = useState<GameMode>(mode);
  const [selectedPlayerMode, setSelectedPlayerMode] = useState<PlayerMode>(playerMode);
  const [selectedGenre, setSelectedGenre] = useState<SongGenre>(genre);
  const [selectedYearFrom, setSelectedYearFrom] = useState(yearFrom ?? DEFAULT_YEAR_FROM);
  const [selectedYearTo, setSelectedYearTo] = useState(yearTo ?? DEFAULT_YEAR_TO);
  const [selectedArtistName, setSelectedArtistName] = useState(artistName ?? "");
  const [artistDropdownOpen, setArtistDropdownOpen] = useState(false);
  const artistResults = useArtistSearch(selectedArtistName, artistDropdownOpen);
  const [selectedArtistNames, setSelectedArtistNames] = useState<string[]>(artistNames ?? []);
  const [multiArtistQuery, setMultiArtistQuery] = useState("");
  const [multiArtistDropdownOpen, setMultiArtistDropdownOpen] = useState(false);
  const multiArtistResults = useArtistSearch(multiArtistQuery, multiArtistDropdownOpen);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  // Usable Songle datasets for the "Song source" picker - the host's own
  // plus any public ones. Fetched once on mount; the list is small and
  // only the host sees this form.
  const [myDatasets, setMyDatasets] = useState<DatasetSummary[]>([]);
  const [communityDatasets, setCommunityDatasets] = useState<DatasetSummary[]>([]);

  useEffect(() => {
    let cancelled = false;
    api
      .get<DatasetsIndex>("/api/datasets", { params: { type: "songle" } })
      .then((response) => {
        if (cancelled) return;
        setMyDatasets(response.data.mine);
        setCommunityDatasets(response.data.community);
      })
      .catch(() => {
        if (!cancelled) {
          setMyDatasets([]);
          setCommunityDatasets([]);
        }
      });
    return () => {
      cancelled = true;
    };
  }, []);

  // Keep the fields in sync if another host tab changes settings first
  // (the live broadcast updates the props this component receives).
  useEffect(() => setSongs(songsPerTier), [songsPerTier]);
  useEffect(() => setSelectedTiers(enabledTiers), [enabledTiers]);
  useEffect(() => setTimeoutSeconds(guessTimeoutSeconds), [guessTimeoutSeconds]);
  useEffect(() => setSelectedMode(mode), [mode]);
  useEffect(() => setSelectedPlayerMode(playerMode), [playerMode]);
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
  useEffect(() => {
    if (artistNames !== null) setSelectedArtistNames(artistNames);
  }, [artistNames]);

  function selectArtist(suggestion: ArtistSuggestion) {
    setSelectedArtistName(suggestion.name);
    setArtistDropdownOpen(false);
    void save({ artist_name: suggestion.name });
  }

  function toggleTier(tier: string) {
    const next = selectedTiers.includes(tier)
      ? selectedTiers.filter((t) => t !== tier)
      : [...selectedTiers, tier];

    if (next.length === 0) return; // at least one tier must stay enabled

    setSelectedTiers(next);
    void save({ enabled_tiers: next });
  }

  function addArtistName(name: string) {
    const trimmed = name.trim();
    const alreadyAdded = selectedArtistNames.some((n) => n.toLowerCase() === trimmed.toLowerCase());

    if (!trimmed || alreadyAdded || selectedArtistNames.length >= MULTI_ARTIST_MAX) return;

    const next = [...selectedArtistNames, trimmed];
    setSelectedArtistNames(next);
    setMultiArtistQuery("");
    setMultiArtistDropdownOpen(false);
    void save({ artist_names: next });
  }

  function removeArtistName(name: string) {
    const next = selectedArtistNames.filter((n) => n !== name);
    setSelectedArtistNames(next);
    void save({ artist_names: next });
  }

  // Accepts explicit overrides rather than only reading state, since a
  // radio's onChange fires before the setState it triggers has re-rendered -
  // saving the new value directly avoids persisting a stale one.
  async function save(overrides: Partial<SettingsPayload> = {}) {
    const effectiveGenre = overrides.genre ?? selectedGenre;
    // `datasetId` (the prop) is the live source of truth - it's kept in
    // sync by the room.settings_updated broadcast. Carry it on every save
    // so an unrelated control change can't drop the selected song source.
    const effectiveDatasetId =
      overrides.dataset_id !== undefined ? overrides.dataset_id : datasetId;
    const payload: SettingsPayload = {
      songs_per_tier: overrides.songs_per_tier ?? songs,
      enabled_tiers: overrides.enabled_tiers ?? selectedTiers,
      guess_timeout_seconds: overrides.guess_timeout_seconds ?? timeout,
      mode: overrides.mode ?? selectedMode,
      player_mode: overrides.player_mode ?? selectedPlayerMode,
      genre: effectiveGenre,
      year_from: effectiveGenre === "year" ? (overrides.year_from ?? selectedYearFrom) : null,
      year_to: effectiveGenre === "year" ? (overrides.year_to ?? selectedYearTo) : null,
      artist_name: effectiveGenre === "artist" ? (overrides.artist_name ?? selectedArtistName) : null,
      artist_names: effectiveGenre === "multi_artist" ? (overrides.artist_names ?? selectedArtistNames) : null,
      dataset_id: effectiveDatasetId,
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

  const isClassic = selectedMode === "classic";
  const datasetActive = datasetId !== null;

  function selectDataset(value: string) {
    if (value === "") {
      // Switching back to automatic selection - the form always submits
      // enabled_tiers/songs_per_tier, so restore sensible defaults here
      // rather than leaving the room stuck on the dataset's easy-only,
      // single-pool shape.
      const allTiers = DIFFICULTY_TIERS.map((tier) => tier.value);
      setSelectedTiers(allTiers);
      setSongs(3);
      void save({ dataset_id: null, enabled_tiers: allTiers, songs_per_tier: 3 });
      return;
    }
    void save({ dataset_id: Number(value) });
  }

  return (
    <form className="room-settings-form" onSubmit={suppressNativeSubmit}>
      <fieldset className="mode-picker">
        <legend>Solo / Multiplayer</legend>
        {PLAYER_MODES.map((option) => (
          <label key={option.value} className="mode-option">
            <input
              type="radio"
              name="player_mode"
              value={option.value}
              checked={selectedPlayerMode === option.value}
              onChange={() => {
                setSelectedPlayerMode(option.value);
                void save({ player_mode: option.value });
              }}
            />
            <span>
              <strong>{option.label}</strong>
              <span className="hint">{option.description}</span>
            </span>
          </label>
        ))}
      </fieldset>
      {!isClassic && (
        <>
          <label>
            Song source
            <select
              value={datasetId ?? ""}
              onChange={(e) => selectDataset(e.target.value)}
            >
              <option value="">Default (auto)</option>
              {myDatasets.length > 0 && (
                <optgroup label="My datasets">
                  {myDatasets.map((dataset) => (
                    <option key={dataset.id} value={dataset.id} disabled={dataset.item_count === 0}>
                      {dataset.name}
                      {dataset.item_count === 0
                        ? " (empty — import a playlist first)"
                        : ` (${dataset.item_count} songs)`}
                    </option>
                  ))}
                </optgroup>
              )}
              {communityDatasets.length > 0 && (
                <optgroup label="Community">
                  {communityDatasets.map((dataset) => (
                    <option key={dataset.id} value={dataset.id} disabled={dataset.item_count === 0}>
                      {dataset.name}
                      {dataset.owner_username ? ` — ${dataset.owner_username}` : ""}
                      {dataset.item_count === 0 ? " (empty)" : ` (${dataset.item_count} songs)`}
                    </option>
                  ))}
                </optgroup>
              )}
            </select>
          </label>
          {datasetActive ? (
            <>
              <p className="hint">
                Using <strong>{datasetName}</strong> — genre, year and artist filters don't
                apply. Rounds are drawn straight from this dataset.
              </p>
              <label>
                Rounds
                <input
                  type="number"
                  min={DATASET_MIN_ROUNDS}
                  max={DATASET_MAX_ROUNDS}
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
            </>
          ) : (
            <>
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
                <legend>Difficulties</legend>
                {DIFFICULTY_TIERS.map((tier) => (
                  <label key={tier.value} className="mode-option">
                    <input
                      type="checkbox"
                      checked={selectedTiers.includes(tier.value)}
                      onChange={() => toggleTier(tier.value)}
                    />
                    <span>{tier.label}</span>
                  </label>
                ))}
              </fieldset>
            </>
          )}
        </>
      )}
      <fieldset className="mode-picker">
        <legend>Mode</legend>
        {GAME_MODES.map((option) => {
          const modeLevel = requiredLevel(`mode:${option.value}`);
          const locked = (hostLevel ?? 1) < modeLevel;

          return (
            <label key={option.value} className={locked ? "mode-option mode-option-locked" : "mode-option"}>
              {locked && (
                <span className="mode-option-lock">
                  <Lock size={13} strokeWidth={2.5} />
                  Locked
                </span>
              )}
              <input
                type="radio"
                name="mode"
                value={option.value}
                checked={selectedMode === option.value}
                disabled={locked}
                onChange={() => {
                  setSelectedMode(option.value);
                  // "iconic" is Classic-exclusive (see songGenres.ts - it's
                  // deliberately not in SONG_GENRES) - carrying it over
                  // when leaving Classic would both fail to match any
                  // Genre radio here and get silently normalized away by
                  // the backend anyway, so reset it locally too.
                  if (option.value !== "classic" && selectedGenre === "iconic") {
                    setSelectedGenre("normal");
                    void save({ mode: option.value, genre: "normal" });
                    return;
                  }
                  void save({ mode: option.value });
                }}
              />
              <span>
                <strong>{option.label}</strong>
                <span className="hint">
                  {locked ? `Unlocks at level ${modeLevel}` : option.description}
                </span>
              </span>
            </label>
          );
        })}
      </fieldset>
      {!isClassic && !datasetActive && (
      <fieldset className="mode-picker">
        <legend>Genre</legend>
        {SONG_GENRES.map((option) => {
          const genreLevel = requiredLevel(`genre:${option.value}`);
          const genreLocked = (hostLevel ?? 1) < genreLevel;

          return (
          <label
            key={option.value}
            className={genreLocked ? "mode-option mode-option-locked" : "mode-option"}
          >
            {genreLocked && (
              <span className="mode-option-lock">
                <Lock size={13} strokeWidth={2.5} />
                Locked
              </span>
            )}
            <input
              type="radio"
              name="genre"
              value={option.value}
              checked={selectedGenre === option.value}
              disabled={genreLocked}
              onChange={() => {
                setSelectedGenre(option.value);
                void save({ genre: option.value });
              }}
            />
            <span>
              <strong>{option.label}</strong>
              <span className="hint">
                {genreLocked ? `Unlocks at level ${genreLevel}` : option.description}
              </span>
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
                <span className="year-range-inputs artist-picker">
                  <label>
                    Artist
                    <input
                      type="text"
                      maxLength={100}
                      value={selectedArtistName}
                      onClick={(e) => e.stopPropagation()}
                      onChange={(e) => {
                        setSelectedArtistName(e.target.value);
                        setArtistDropdownOpen(true);
                      }}
                      onFocus={() => setArtistDropdownOpen(true)}
                      onBlur={() => {
                        setTimeout(() => setArtistDropdownOpen(false), 150);
                        void save({ artist_name: selectedArtistName });
                      }}
                    />
                  </label>
                  {artistDropdownOpen && artistResults.length > 0 && (
                    <ul className="guess-suggestions">
                      {artistResults.map((artist) => (
                        <li key={artist.provider_artist_id}>
                          {artist.picture_url ? (
                            <img
                              className="suggestion-art"
                              src={artist.picture_url}
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
                            onClick={() => selectArtist(artist)}
                          >
                            {artist.name}
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                </span>
              )}
              {option.value === "multi_artist" && selectedGenre === "multi_artist" && (
                <span className="year-range-inputs artist-picker">
                  <span className="artist-search">
                    <label>
                      Add an artist
                      <input
                        type="text"
                        maxLength={100}
                        value={multiArtistQuery}
                        disabled={selectedArtistNames.length >= MULTI_ARTIST_MAX}
                        onClick={(e) => e.stopPropagation()}
                        onChange={(e) => {
                          setMultiArtistQuery(e.target.value);
                          setMultiArtistDropdownOpen(true);
                        }}
                        onFocus={() => setMultiArtistDropdownOpen(true)}
                        onBlur={() => setTimeout(() => setMultiArtistDropdownOpen(false), 150)}
                      />
                    </label>
                    {multiArtistDropdownOpen && multiArtistResults.length > 0 && (
                      <ul className="guess-suggestions">
                        {multiArtistResults.map((artist) => (
                          <li key={artist.provider_artist_id}>
                            {artist.picture_url ? (
                              <img
                                className="suggestion-art"
                                src={artist.picture_url}
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
                              onClick={() => addArtistName(artist.name)}
                            >
                              {artist.name}
                            </button>
                          </li>
                        ))}
                      </ul>
                    )}
                  </span>
                  {selectedArtistNames.length > 0 && (
                    <ul className="player-list artist-chip-list">
                      {selectedArtistNames.map((name) => (
                        <li key={name}>
                          <span>{name}</span>
                          <button
                            type="button"
                            className="button-secondary"
                            onClick={(e) => {
                              e.stopPropagation();
                              removeArtistName(name);
                            }}
                          >
                            Remove
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                </span>
              )}
            </span>
          </label>
          );
        })}
      </fieldset>
      )}
      {error && <p className="form-error">{error}</p>}
      <p className="hint save-status" aria-live="polite">
        {saving ? "Saving…" : saved ? "Saved" : " "}
      </p>
    </form>
  );
}
