import { useEffect, useState } from "react";

const STORAGE_KEY = "neogames_volume";
const DEFAULT_VOLUME = 0.8;

function readStoredVolume(): number {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw === null) return DEFAULT_VOLUME;
    const parsed = Number(raw);
    return Number.isFinite(parsed) ? Math.min(1, Math.max(0, parsed)) : DEFAULT_VOLUME;
  } catch {
    return DEFAULT_VOLUME;
  }
}

/**
 * Per-browser volume preference (not per-viewer game state), shared by the
 * round's clip player and the guess-autocomplete preview player.
 */
export function useVolume(): [number, (value: number) => void] {
  const [volume, setVolume] = useState(readStoredVolume);

  useEffect(() => {
    try {
      localStorage.setItem(STORAGE_KEY, String(volume));
    } catch {
      // Ignore write failures (private browsing, storage disabled, etc).
    }
  }, [volume]);

  return [volume, setVolume];
}
