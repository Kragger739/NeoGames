import { useEffect, useState } from "react";
import { Lock } from "lucide-react";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import {
  ICONIC_EXTEND_MAX,
  ICONIC_EXTEND_MIN,
  ICONIC_MODES,
  ICONIC_ROUND_MAX,
  ICONIC_ROUND_MIN,
  iconicModeKey,
} from "../lib/iconicSeries";
import type { GameMode, PlayerMode, SettingsPayload } from "../lib/roomTypes";
import { useUnlockStore } from "../stores/unlockStore";

interface Props {
  code: string;
  artistName: string;
  songsPerTier: number;
  guessTimeoutSeconds: number;
  mode: GameMode;
  playerMode: PlayerMode;
  hostLevel: number | null;
}

/**
 * The Iconic Artist series' cut-down replacement for RoomSettingsForm:
 * one of three modes plus round count and auto-extend. Every change PATCHes
 * the full settings payload with genre/artist/tiers pinned, so the backend's
 * forcing logic in GameRoomController::update() has nothing to fight.
 */
export function IconicArtistLobbySettings({
  code,
  artistName,
  songsPerTier,
  guessTimeoutSeconds,
  mode,
  playerMode,
  hostLevel,
}: Props) {
  const fetchUnlocks = useUnlockStore((state) => state.fetch);
  const requiredLevel = useUnlockStore((state) => state.requiredLevel);

  const [selectedKey, setSelectedKey] = useState(() => iconicModeKey(mode, playerMode));
  const [rounds, setRounds] = useState(songsPerTier);
  const [autoExtend, setAutoExtend] = useState(guessTimeoutSeconds);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    void fetchUnlocks();
  }, [fetchUnlocks]);

  // Keep in sync if another tab (or a broadcast) changes the room.
  useEffect(() => setSelectedKey(iconicModeKey(mode, playerMode)), [mode, playerMode]);
  useEffect(() => setRounds(songsPerTier), [songsPerTier]);
  useEffect(() => setAutoExtend(guessTimeoutSeconds), [guessTimeoutSeconds]);

  async function save(overrides: { key?: string; rounds?: number; autoExtend?: number } = {}) {
    const key = overrides.key ?? selectedKey;
    const chosen = ICONIC_MODES.find((m) => m.key === key) ?? ICONIC_MODES[0];
    const payload: SettingsPayload = {
      songs_per_tier: overrides.rounds ?? rounds,
      enabled_tiers: ["easy"],
      guess_timeout_seconds: overrides.autoExtend ?? autoExtend,
      mode: chosen.mode,
      player_mode: chosen.player_mode,
      genre: "artist",
      year_from: null,
      year_to: null,
      artist_name: artistName,
      artist_names: null,
      dataset_id: null,
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

  const soloSelected = selectedKey === "singleplayer";

  return (
    // A <form> (submit suppressed) rather than a <div> so the shared
    // `form label` column layout applies - same trick as RoomSettingsForm.
    <form
      className="room-settings-form iconic-lobby-settings"
      onSubmit={(e) => e.preventDefault()}
    >
      <fieldset className="mode-picker">
        <legend>Mode</legend>
        {ICONIC_MODES.map((option) => {
          const lvl = option.unlockKey ? requiredLevel(option.unlockKey) : 1;
          const locked = option.unlockKey != null && (hostLevel ?? 1) < lvl;
          return (
            <label
              key={option.key}
              className={locked ? "mode-option mode-option-locked" : "mode-option"}
            >
              {locked && (
                <span className="mode-option-lock">
                  <Lock size={13} strokeWidth={2.5} />
                  Locked
                </span>
              )}
              <input
                type="radio"
                name="iconic_mode"
                value={option.key}
                checked={selectedKey === option.key}
                disabled={locked}
                onChange={() => {
                  setSelectedKey(option.key);
                  void save({ key: option.key });
                }}
              />
              <span>
                <strong>{option.label}</strong>
                <span className="hint">
                  {locked ? `Unlocks at level ${lvl}` : option.description}
                </span>
              </span>
            </label>
          );
        })}
      </fieldset>

      <label>
        Rounds
        <input
          type="number"
          min={ICONIC_ROUND_MIN}
          max={ICONIC_ROUND_MAX}
          value={rounds}
          onChange={(e) => setRounds(Number(e.target.value))}
          onBlur={() => void save({ rounds })}
        />
      </label>
      <p className="hint">A short catalogue starts repeating songs past ~10 rounds.</p>

      {!soloSelected && (
        <label>
          Seconds before a clip auto-extends
          <input
            type="number"
            min={ICONIC_EXTEND_MIN}
            max={ICONIC_EXTEND_MAX}
            value={autoExtend}
            onChange={(e) => setAutoExtend(Number(e.target.value))}
            onBlur={() => void save({ autoExtend })}
          />
        </label>
      )}

      {error && <p className="form-error">{error}</p>}
      <p className="hint save-status" aria-live="polite">
        {saving ? "Saving…" : saved ? "Saved" : ""}
      </p>
    </form>
  );
}
