import { create } from "zustand";

/**
 * Theme preference, three-state:
 *  - 'system' (default): follow the OS prefers-color-scheme, live
 *  - 'light' / 'dark': explicit, always wins
 *
 * The resolved 'light'|'dark' is stamped onto <html data-theme>, which is
 * the single hook every CSS token override keys off (index.css). An
 * inline script in index.html applies the same resolution before first
 * paint; this store keeps it in sync afterward and drives the
 * ProfilePage control. Preference lives in localStorage only - no
 * backend, no cross-device sync by design.
 */
export type ThemePref = "system" | "light" | "dark";
export type ResolvedTheme = "light" | "dark";

const STORAGE_KEY = "neo-theme";

const prefersDark = () =>
  typeof window !== "undefined" &&
  window.matchMedia("(prefers-color-scheme: dark)").matches;

function readStoredPref(): ThemePref {
  try {
    const v = localStorage.getItem(STORAGE_KEY);
    return v === "light" || v === "dark" ? v : "system";
  } catch {
    return "system";
  }
}

export function resolveTheme(pref: ThemePref): ResolvedTheme {
  if (pref === "light" || pref === "dark") return pref;
  return prefersDark() ? "dark" : "light";
}

function applyResolved(resolved: ResolvedTheme) {
  if (typeof document !== "undefined") {
    document.documentElement.dataset.theme = resolved;
  }
}

interface ThemeState {
  pref: ThemePref;
  resolved: ResolvedTheme;
  setPref: (pref: ThemePref) => void;
}

const initialPref = readStoredPref();

export const useThemeStore = create<ThemeState>((set) => ({
  pref: initialPref,
  resolved: resolveTheme(initialPref),
  setPref: (pref) => {
    try {
      if (pref === "system") localStorage.removeItem(STORAGE_KEY);
      else localStorage.setItem(STORAGE_KEY, pref);
    } catch {
      // Private-mode / storage-disabled: preference just won't persist.
    }
    const resolved = resolveTheme(pref);
    applyResolved(resolved);
    set({ pref, resolved });
  },
}));

/**
 * Called once from main.tsx. Re-applies the resolved theme (the inline
 * script already did this, so it's a no-op on first load) and starts
 * tracking OS changes while the user is on 'system'.
 */
export function initTheme() {
  applyResolved(useThemeStore.getState().resolved);

  if (typeof window === "undefined") return;

  window
    .matchMedia("(prefers-color-scheme: dark)")
    .addEventListener("change", () => {
      if (useThemeStore.getState().pref !== "system") return;
      const resolved: ResolvedTheme = prefersDark() ? "dark" : "light";
      applyResolved(resolved);
      useThemeStore.setState({ resolved });
    });
}
