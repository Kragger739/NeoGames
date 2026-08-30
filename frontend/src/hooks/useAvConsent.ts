import { useCallback, useSyncExternalStore } from "react";

const KEY = "ddf-av-consent";
const listeners = new Set<() => void>();

function read(): boolean {
  try {
    return localStorage.getItem(KEY) === "1";
  } catch {
    return false;
  }
}

function subscribe(cb: () => void) {
  listeners.add(cb);
  return () => listeners.delete(cb);
}

/**
 * One-time consent for the "Der Dümmste fliegt" camera/microphone sharing.
 * The streams go peer-to-peer to every other player, so we surface an
 * explicit notice before requesting device access. Persisted per browser
 * and shared across every hook consumer so they re-render together.
 */
export function useAvConsent() {
  const granted = useSyncExternalStore(subscribe, read, () => false);

  const grant = useCallback(() => {
    try {
      localStorage.setItem(KEY, "1");
    } catch {
      // Non-fatal: consent just won't persist across reloads.
    }
    listeners.forEach((cb) => cb());
  }, []);

  return { granted, grant };
}
